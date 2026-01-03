# Cross-platform background app: CTRL+SHIFT+X -> screenshot -> Vision API -> floating popup
# STEALTH MODE VERSION - максимально незаметный вывод

import os
import io
import sys
import base64
import tempfile
import time
import random
import threading
import queue
import traceback
import tkinter as tk
from typing import Optional

import requests
from pynput import keyboard
from mss import mss, tools
try:
    import keyboard as kb_lib  # Windows-friendly global hotkeys
except Exception:
    kb_lib = None

# Load environment variables from .env file if it exists


os.environ.update({
    "GROQ_API_KEY": "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",
    "QUICKVISION_BACKEND": "groq",
    "QUICKVISION_ALPHA": "0.8",
    "QUICKVISION_FONT_SIZE": "7",
    "QUICKVISION_DURATION_MS": "2500",
    "QUICKVISION_OUTPUT_MODE": "telegram",
    "TELEGRAM_CHAT_ID": "1842835871",
    "TELEGRAM_BOT_TOKEN": "8185067884:AAHKxXhHl9BCmx_lgxg5ZDgb0MPw8FABcqc",
})


# ------------------------------
# UI: Small always-on-top popup
# ------------------------------
class PopupUI:
    """Manages a hidden Tk root and shows transient, borderless, topmost popups."""

    def __init__(self) -> None:
        self.root = tk.Tk()
        self.root.withdraw()

        # CRITICAL: Make root window completely non-interactive
        try:
            if sys.platform == "darwin":
                # macOS: prevent root from ever taking focus
                self.root.tk.call("::tk::unsupported::MacWindowStyle", "style",
                                 self.root._w, "help", "noActivates")
        except:
            pass

        self._queue: "queue.Queue[tuple[str, int, bool]]" = queue.Queue()
        self.root.after(100, self._process_queue)

    def _process_queue(self) -> None:
        """Poll the queue and show popups for any pending messages."""
        try:
            while True:
                text, duration_ms, is_error = self._queue.get_nowait()
                self._show_popup(text=text, duration_ms=duration_ms, is_error=is_error)
        except queue.Empty:
            pass
        finally:
            self.root.after(100, self._process_queue)

    def show(self, text: str, duration_ms: int = 4000, is_error: bool = False) -> None:
        """Thread-safe method to request a popup."""
        self._queue.put((text, duration_ms, is_error))

    def _show_macos_notification(self, text: str, is_error: bool) -> bool:
        """Show native macOS notification. Returns True if successful."""
        import subprocess

        mode_env = os.getenv("QUICKVISION_TEXT_MODE") or os.getenv("QUICKVISION_OUTPUT_FORMAT") or os.getenv("QUICKVISION_OUTPUT_MODE", "minimal")
        mode = mode_env.lower()
        if mode not in ("minimal", "letters"):
            mode = "minimal"
        if mode == "minimal":
            compact = self._extract_minimal_pairs(text)
        else:
            compact = self._extract_compact_answer(text)

        print(f"Showing notification: {compact}")

        title = "⚠️ Error" if is_error else "✓ Answer"

        # Escape quotes in text
        compact_safe = compact.replace('"', '\\"').replace("'", "\\'").replace('`', '\\`')

        # Use osascript to show notification
        sound = os.getenv("QUICKVISION_NOTIFICATION_SOUND", "").strip()
        if sound:
            script = f'''display notification "{compact_safe}" with title "{title}" sound name "{sound}"'''
        else:
            script = f'''display notification "{compact_safe}" with title "{title}"'''

        try:
            result = subprocess.run(['osascript', '-e', script],
                          capture_output=True,
                          timeout=3,
                          text=True)

            if result.returncode == 0:
                print("Notification sent successfully")
                return True
            else:
                print(f"Notification failed: {result.stderr}")
                return False
        except Exception as e:
            print(f"Notification exception: {e}")
            return False

    def _extract_compact_answer(self, text: str) -> str:
        """Extract only answer letters from the response."""
        import re

        results = []

        # Strong structured patterns first: 1) A 2) B ...
        numbered = re.findall(r'(?:^|\n)\s*(\d{1,2})\s*[\)\.\-:]\s*([A-D])\b', text, re.IGNORECASE)
        if numbered:
            # Deduplicate by question number, preserve order
            seen = set()
            out = []
            for qn, ans in numbered:
                if qn not in seen:
                    out.append(f"{int(qn)}) {ans.upper()}")
                    seen.add(qn)
            if out:
                return " ".join(out[:15])

        # Variants: "Answer: A", "Correct answer: B" possibly per line
        per_line = []
        for m in re.finditer(r'(?m)^(?:\s*(?:final\s+)?answer(?:\s+is)?\s*[:\-]?\s*([A-D])\b)', text, re.IGNORECASE):
            per_line.append(m.group(1).upper())
        if per_line:
            return " ".join(f"{i+1}) {a}" for i, a in enumerate(per_line[:15]))

        # Markdown patterns used before
        for pattern in (
            r'\*{2,}Answer:\*{2,}\s+\*{2,}([A-D])\)',
            r'\*{2,}Answer:\*{2,}\s+([A-D])\)'
        ):
            for m in re.finditer(pattern, text, re.IGNORECASE):
                results.append(m.group(1).upper())
        if results:
            return " ".join(f"{i+1}) {a}" for i, a in enumerate(results[:15]))

        # Fallback: detect standalone choices like ") A" sequences
        all_answers = re.findall(r'\b([A-D])\)\s+[A-Z]', text)
        if all_answers:
            unique = []
            for a in all_answers:
                a = a.upper()
                if a not in unique:
                    unique.append(a)
            return " ".join(f"{i+1}) {a}" for i, a in enumerate(unique[:15]))

        return text[:100] + "..." if len(text) > 100 else text

    def _extract_minimal_pairs(self, text: str) -> str:
        import re
        pairs = []
        for m in re.finditer(r'([A-D])\)', text, re.IGNORECASE):
            idx = m.start()
            num_match = None
            for nm in re.finditer(r'(?:^|\n)\s*(\d{1,2})[\.)]', text[:idx]):
                num_match = nm
            if num_match:
                qn = num_match.group(1)
                ans = m.group(1).upper()
                pairs.append((qn, ans))
        if not pairs:
            for qm in re.finditer(r'Question\s+(\d+)\b[\s\S]*?([A-D])\)', text, re.IGNORECASE):
                pairs.append((qm.group(1), qm.group(2).upper()))

        out = []
        seen = set()
        for qn, ans in pairs:
            if qn not in seen:
                out.append(f"{qn}:{ans}")
                seen.add(qn)
        if out:
            return " ".join(out[:10])
        letters = re.findall(r'\b([A-D])\)', text, re.IGNORECASE)
        if letters:
            return " ".join(f"{i+1}:{a.upper()}" for i, a in enumerate(letters[:10]))
        return text[:60] + "..." if len(text) > 60 else text

    def _show_popup(self, text: str, duration_ms: int, is_error: bool) -> None:
        """Show notification - on macOS use native notifications only."""

        print(f"Showing popup with text: {text[:200]}...")

        if sys.platform == "darwin":
            mode = os.getenv("QUICKVISION_MAC_POPUP_MODE", "both").lower()
            use_notification = mode in ("notification", "both")
            use_tk = mode in ("tk", "both")
            notified = False
            if use_notification:
                success = self._show_macos_notification(text, is_error)
                if success:
                    print("Native notification shown successfully")
                    notified = True
                else:
                    print("Native notification failed, showing tkinter popup")
                    use_tk = True
            if not use_tk and notified:
                return

        # Fallback to tkinter popup for non-macOS or if notification failed
        win = tk.Toplevel(self.root)
        win.withdraw()
        win.overrideredirect(True)

        try:
            if sys.platform.startswith("win"):
                try:
                    win.attributes("-toolwindow", True)
                except tk.TclError:
                    pass

            # STEALTH: Увеличенная прозрачность для незаметности
            win.attributes("-alpha", float(os.getenv("QUICKVISION_ALPHA", "0.3")))
            win.attributes("-topmost", True)
            if sys.platform == "darwin":
                try:
                    self.root.tk.call("::tk::unsupported::MacWindowStyle", "style", win._w, "help", "noActivates")
                except Exception:
                    pass
                try:
                    win.attributes("-disabled", True)
                except tk.TclError:
                    pass
                try:
                    win.configure(takefocus=0)
                except Exception:
                    pass

        except tk.TclError as e:
            print(f"Window attributes error: {e}")

        # STEALTH: Более нейтральные цвета
        if is_error:
            bg = "#8b0000"
            fg = "#ffffff"
        else:
            bg = os.getenv("QUICKVISION_BG_COLOR", "#1a1a1a")
            fg = os.getenv("QUICKVISION_FG_COLOR", "#666666")

        pad = int(os.getenv("QUICKVISION_PADDING", "2"))
        display_mode_env = os.getenv("QUICKVISION_TEXT_MODE") or os.getenv("QUICKVISION_OUTPUT_FORMAT") or os.getenv("QUICKVISION_OUTPUT_MODE", "minimal")
        display_mode = display_mode_env.lower()
        if display_mode not in ("minimal", "letters"):
            display_mode = "minimal"
        display_text = text
        if display_mode == "minimal":
            display_text = self._extract_minimal_pairs(text)
        elif display_mode == "letters":
            display_text = self._extract_compact_answer(text)

        label = tk.Label(
            win,
            text=display_text,
            bg=bg,
            fg=fg,
            justify="left",
            anchor="w",
            font=("Arial", int(os.getenv("QUICKVISION_FONT_SIZE", "5"))),
            wraplength=int(os.getenv("QUICKVISION_WRAP", "120")),
            padx=pad,
            pady=pad,
        )
        label.pack()

        win.update_idletasks()
        width = label.winfo_reqwidth()
        height = label.winfo_reqheight()

        sw = win.winfo_screenwidth()
        sh = win.winfo_screenheight()

        # STEALTH: Позиционирование можно настроить
        position = os.getenv("QUICKVISION_POSITION", "bottom-left").lower()
        margin_left = int(os.getenv("QUICKVISION_MARGIN_LEFT", "20"))
        margin_bottom = int(os.getenv("QUICKVISION_MARGIN_BOTTOM", "50"))
        margin_right = int(os.getenv("QUICKVISION_MARGIN_RIGHT", "20"))
        margin_top = int(os.getenv("QUICKVISION_MARGIN_TOP", "50"))

        if position == "bottom-left":
            x = margin_left
            y = sh - height - margin_bottom
        elif position == "bottom-right":
            x = sw - width - margin_right
            y = sh - height - margin_bottom
        elif position == "top-left":
            x = margin_left
            y = margin_top
        elif position == "top-right":
            x = sw - width - margin_right
            y = margin_top
        elif position == "center":
            x = (sw - width) // 2
            y = (sh - height) // 2
        else:
            x = margin_left
            y = sh - height - margin_bottom

        if y < 0:
            y = margin_bottom
        if x + width > sw:
            x = sw - width - margin_left

        win.geometry(f"{width}x{height}+{x}+{y}")
        win.deiconify()

        try:
            if sys.platform != "darwin":
                win.lift()
        except Exception:
            pass

        win.after(duration_ms, win.destroy)

    def run(self) -> None:
        """Start the Tk event loop in the main thread."""
        self.root.mainloop()


# --------------------------------------
# Screenshot capture using mss.grab
# --------------------------------------
def capture_primary_monitor_png_bytes() -> bytes:
    """Capture the entire primary monitor and return PNG bytes without saving to disk."""
    with mss() as sct:
        monitor = sct.monitors[1]
        sct_img = sct.grab(monitor)
        try:
            png_bytes = tools.to_png(sct_img.rgb, sct_img.size)
            if isinstance(png_bytes, (bytes, bytearray)):
                return bytes(png_bytes)
        except TypeError:
            pass

        fd, tmp_path = tempfile.mkstemp(suffix=".png")
        try:
            os.close(fd)
            tools.to_png(sct_img.rgb, sct_img.size, output=tmp_path)
            with open(tmp_path, "rb") as f:
                return f.read()
        finally:
            try:
                os.remove(tmp_path)
            except Exception:
                pass


# --------------------------------------
# Groq Vision API call
# --------------------------------------
def call_groq_vision(
    png_bytes: bytes,
    prompt: Optional[str],
    api_key: str,
    model: str = "meta-llama/llama-4-scout-17b-16e-instruct",
) -> str:
    """Send the screenshot to Groq Vision API and return the text answer."""
    api_key = os.getenv("GROQ_API_KEY", api_key)
    if not api_key:
        raise RuntimeError("GROQ_API_KEY is not set.")

    b64 = base64.b64encode(png_bytes).decode("ascii")
    data_url = f"data:image/png;base64,{b64}"

    url = "https://api.groq.com/openai/v1/responses"
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
    }

    user_prompt = (
        prompt
        or "I'm taking a test and will send you screenshots. Return ONLY the final answers You are an assistant that sees an image of a test and provides only answers without explanations. If the question is matching, give the answers as pairs like Python - print, Java - System.out.print. If the question is single-choice, give only the answer like 1) A. If the question has multiple correct options, list all of them like 1) A B C. If the question is drag & drop / ordering, give answers in order like 1) Python 2) Java 3) C++. Do not write any explanations or reasoning. Work directly from the image and extract the question text automatically.,  No explanations, no extra text."
    )

    payload = {
        "model": model,
        "input": [
            {
                "role": "user",
                "content": [
                    {
                        "type": "input_text",
                        "text": user_prompt,
                    },
                    {
                        "type": "input_image",
                        "detail": "auto",
                        "image_url": data_url,
                    },
                ],
            }
        ],
    }

    try:
        resp = requests.post(url, headers=headers, json=payload, timeout=(10, 60))
        resp.raise_for_status()
        j = resp.json()

        result = None

        # Groq's format: output array with message objects
        if "output" in j and isinstance(j["output"], list):
            for output_item in j["output"]:
                if isinstance(output_item, dict) and output_item.get("type") == "message":
                    content = output_item.get("content", [])
                    if isinstance(content, list):
                        for content_item in content:
                            if isinstance(content_item, dict) and content_item.get("type") == "output_text":
                                result = content_item.get("text", "")
                                break
                if result:
                    break

        if not result and isinstance(j, list) and len(j) > 0:
            first_item = j[0]
            if "content" in first_item and isinstance(first_item["content"], list):
                for content_item in first_item["content"]:
                    if isinstance(content_item, dict) and content_item.get("type") == "output_text":
                        result = content_item.get("text", "")
                        break

        if not result and isinstance(j, dict):
            if "output_text" in j:
                result = j.get("output_text")
                if isinstance(result, list):
                    result = " ".join(str(item) for item in result if item)
            elif "choices" in j and len(j["choices"]) > 0:
                result = j["choices"][0].get("message", {}).get("content")
            elif "response" in j:
                result = j.get("response")

        if result:
            return str(result).strip() if result else ""

        raise RuntimeError(f"Unexpected Groq API response format: {j}")

    except requests.HTTPError as e:
        body_snippet = e.response.text[:500] if e.response is not None else str(e)
        raise RuntimeError(
            f"Groq API HTTP error: {e.response.status_code if e.response else ''} {body_snippet}"
        ) from e
    except requests.RequestException as e:
        raise RuntimeError(f"Network error contacting Groq: {e}") from e





def _is_transient_error_message(msg: str) -> bool:
    """Heuristic to decide if an error is transient and worth retrying."""
    m = msg.lower()
    return (
        "429" in m
        or "rate" in m
        or "temporar" in m
        or "timeout" in m
        or "timed out" in m
        or "connection" in m
        or " 500" in m
        or " 502" in m
        or " 503" in m
        or " 504" in m
    )

def send_telegram_message(text: str, bot_token: str, chat_id: str) -> bool:
    """Send a message via Telegram Bot API."""
    try:
        url = f"https://api.telegram.org/bot{bot_token}/sendMessage"
        payload = {
            "chat_id": chat_id,
            "text": text[:4096],
            "parse_mode": "Markdown",
        }
        resp = requests.post(url, json=payload, timeout=10)
        print(f"Telegram API Response: {resp.status_code} - {resp.text}")
        if resp.status_code == 200:
            j = resp.json()
            return j.get("ok", False)
        return False
    except Exception as e:
        print(f"Telegram send error: {e}")
        traceback.print_exc()
        return False


# --------------------------------------
# Hotkey workflow wiring
# --------------------------------------
class App:
    """Main application wiring: hotkey listener, screenshot, API call, and popup display."""

    def __init__(self) -> None:
        self.ui = PopupUI()

        # API keys (Groq only)
        self.groq_key = os.getenv("GROQ_API_KEY", "")

        self.prompt = os.getenv(
            "QUICKVISION_PROMPT",
            "I'm taking a test and will send you screenshots. Just send me the answers. Just give me the correct answer.You are an assistant that sees an image of a test and provides only answers without explanations. If the question is matching, give the answers as pairs like Python - print, Java - System.out.print. If the question is single-choice, give only the answer like 1) A. If the question has multiple correct options, list all of them like 1) A B C. If the question is drag & drop / ordering, give answers in order like 1) Python 2) Java 3) C++. Do not write any explanations or reasoning. Work directly from the image and extract the question text automatically.",
        )

        # Force Groq backend only
        self.backend = "groq"
        # Model
        self.groq_model = os.getenv("GROQ_MODEL", "meta-llama/llama-4-scout-17b-16e-instruct")

        # Hotkey listener
        self.listener = None
        self._pressed_keys = set()
        self.hotkey = "ctrl+shift+x"
        if sys.platform.startswith("win") and kb_lib is not None:
            try:
                kb_lib.add_hotkey(
                    self.hotkey,
                    self._on_hotkey,
                    suppress=False,
                    timeout=1,
                    trigger_on_release=False,
                )
                print(f"Windows keyboard hotkey registered: {self.hotkey}")
            except Exception as e:
                print(f"Windows hotkey registration failed, falling back to pynput: {e}")
                self.listener = keyboard.Listener(on_press=self._on_key_press, on_release=self._on_key_release)
        else:
            self.listener = keyboard.Listener(on_press=self._on_key_press, on_release=self._on_key_release)

        # Test Telegram send on script start for debugging
        channel = os.getenv("QUICKVISION_OUTPUT_MODE", "popup").strip().lower()
        print(f"Debug: Loaded QUICKVISION_OUTPUT_MODE = {channel}")
        if channel in ("telegram", "both"):
            bot_token = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
            chat_id = os.getenv("TELEGRAM_CHAT_ID", "").strip()
            print(f"Debug: Loaded TELEGRAM_BOT_TOKEN = {bot_token}")
            print(f"Debug: Loaded TELEGRAM_CHAT_ID = {chat_id}")
            if bot_token and chat_id:
                print("Sending test Telegram message on start...")
                test_text = "Test message from script start"
                send_telegram_message(test_text, bot_token, chat_id)
            else:
                print("Telegram config missing on start.")

    def _on_key_press(self, key):
        """Track pressed keys and trigger hotkey."""
        try:
            self._pressed_keys.add(key)

            ctrl_pressed = (
                keyboard.Key.ctrl_l in self._pressed_keys or
                keyboard.Key.ctrl_r in self._pressed_keys or
                keyboard.Key.ctrl in self._pressed_keys
            )
            shift_pressed = (
                keyboard.Key.shift_l in self._pressed_keys or
                keyboard.Key.shift_r in self._pressed_keys or
                keyboard.Key.shift in self._pressed_keys
            )

            x_pressed = False
            try:
                if hasattr(key, 'char') and key.char and key.char.lower() == 'x':
                    x_pressed = True
            except AttributeError:
                pass

            if ctrl_pressed and shift_pressed and x_pressed:
                self._on_hotkey()

        except Exception as e:
            print(f"Key press error: {e}")

    def _on_key_release(self, key):
        """Remove released key from the set."""
        try:
            self._pressed_keys.discard(key)
        except Exception:
            pass

    def _on_hotkey(self) -> None:
        """Trigger the workflow in a background thread."""
        threading.Thread(target=self._run_workflow, daemon=True).start()

    def _run_workflow(self) -> None:
        try:
            print("=== Workflow started ===")

            # Capture screenshot
            try:
                print("Capturing screenshot...")
                png_bytes = capture_primary_monitor_png_bytes()
                print(f"Screenshot captured: {len(png_bytes)} bytes")
            except Exception as e:
                error_msg = f"Screen capture failed: {e}"
                print(error_msg)
                self.ui.show(error_msg, is_error=True)
                return

            result = None
            last_err = None

            print(f"Using backend: {self.backend}")

            # Call backend (Groq only)
            if self.backend == "groq":
                for delay in (0, 1, 2):
                    if delay:
                        print(f"Retrying after {delay}s...")
                        time.sleep(delay)
                    try:
                        print("Calling Groq API...")
                        result = call_groq_vision(
                            png_bytes,
                            self.prompt,
                            self.groq_key,
                            self.groq_model,
                        )
                        print(f"Got result: {result[:100]}...")
                        break
                    except Exception as e:
                        print(f"Groq API error: {e}")
                        last_err = e
                        msg = str(e).lower()
                        if not _is_transient_error_message(msg):
                            break

            if result is None:
                error_msg = str(last_err) if last_err else "Failed to get response."
                print(f"No result: {error_msg}")
                self.ui.show(error_msg, is_error=True)
                return

            if not result:
                result = "(No content returned.)"

            print(f"Final result: {result}")

            channel = os.getenv("QUICKVISION_OUTPUT_MODE", "popup").strip().lower()

            # Telegram handling only
            tg_sent = False
            if channel in ("telegram", "both"):
                bot_token = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
                chat_id = os.getenv("TELEGRAM_CHAT_ID", "").strip()
                if bot_token and chat_id:
                    try:
                        tg_text = self.ui._extract_compact_answer(result)
                        if not tg_text or tg_text.strip() == result.strip():
                            alt = self.ui._extract_minimal_pairs(result)
                            tg_text = alt if alt else ""
                    except Exception:
                        try:
                            tg_text = self.ui._extract_minimal_pairs(result)
                        except Exception:
                            tg_text = ""
                    print(f"Bot token (workflow): {bot_token}")
                    print(f"Chat id (workflow): {chat_id}")
                    print(f"TG text: {tg_text}")
                    if tg_text.strip():
                        tg_sent = send_telegram_message(tg_text, bot_token, chat_id)
                    else:
                        print("No compact answer extracted; skipping Telegram send.")
                        tg_sent = False
                    print(f"Telegram sent: {tg_sent}")
                else:
                    print("Telegram config missing: TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID.")

            if channel == "telegram" and tg_sent:
                return

            # No other output modes; fallback to minimal popup if not telegram
            duration_env = os.getenv("QUICKVISION_DURATION_MS", "1500")
            try:
                duration_ms = max(800, int(duration_env))
            except ValueError:
                duration_ms = 1500
            if channel not in ("telegram",):
                self.ui.show(result, duration_ms=duration_ms)

        except Exception:
            err = traceback.format_exc()
            print(f"Workflow error:\n{err}")
            self.ui.show(f"Unexpected error: {err.splitlines()[-1]}", is_error=True)

    def run(self) -> None:
        """Start the hotkey listener and Tk event loop."""
        if self.listener is not None:
            try:
                self.listener.start()
            except Exception as e:
                self.ui.show(
                    f"Hotkey listener failed. Grant Accessibility permissions. Details: {e}",
                    is_error=True,
                )

        # Check API key (Groq)
        if not (self.groq_key or os.getenv("GROQ_API_KEY")):
            self.ui.show("GROQ_API_KEY is not set.", is_error=True)
        else:
            self.groq_key = os.getenv("GROQ_API_KEY", self.groq_key)

        self.ui.run()


def main() -> None:
    app = App()
    app.run()


if __name__ == "__main__":
    main()