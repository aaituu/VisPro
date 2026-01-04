#!/usr/bin/env python3
"""
QuickVision Client - Server-Based Version
Все API ключи и токены хранятся на сервере
"""

import os
import sys
import json
import base64
import tempfile
import platform
import threading
import requests
from typing import Optional, Dict, Any
from pathlib import Path

try:
    from mss import mss, tools
    from pynput import keyboard
except ImportError:
    print("Error: Required libraries not installed")
    print("Install: pip install mss pynput requests")
    sys.exit(1)

# ==============================================
# КОНФИГУРАЦИЯ - ИЗМЕНИТЕ ПОД СВОЙ СЕРВЕР
# ==============================================
API_BASE_URL = "https://tamada-games.lol/api"  # ← ЗАМЕНИТЕ НА ВАШ ДОМЕН

# Файл для хранения активации
ACTIVATION_FILE = Path.home() / ".quickvision_activation.json"


# ==============================================
# ГЛАВНЫЙ КЛАСС КЛИЕНТА
# ==============================================
class QuickVisionClient:
    """Клиент работает только через сервер"""

    def __init__(self):
        self.activation_code: Optional[str] = None
        self.user_id: Optional[int] = None
        self.chat_id: Optional[str] = None
        self.running = True
        self._pressed_keys = set()

        print("\n" + "=" * 60)
        print("QuickVision Client")
        print("=" * 60)

        # Загружаем сохраненную активацию
        self.load_activation()

        # Если нет кода - запрашиваем
        if not self.activation_code:
            self.request_activation()
        else:
            print(f"✓ Saved activation found: {self.activation_code[:8]}...")
            self.verify_activation()

    # ------------------------------------------
    # АКТИВАЦИЯ
    # ------------------------------------------

    def load_activation(self) -> bool:
        """Загрузка сохраненного кода активации"""
        try:
            if ACTIVATION_FILE.exists():
                with open(ACTIVATION_FILE, 'r') as f:
                    data = json.load(f)
                    self.activation_code = data.get('activation_code')
                    self.user_id = data.get('user_id')
                    self.chat_id = data.get('chat_id')
                    return True
        except Exception as e:
            print(f"Warning: Failed to load activation: {e}")
        return False

    def save_activation(self, data: Dict[str, Any]):
        """Сохранение данных активации"""
        try:
            with open(ACTIVATION_FILE, 'w') as f:
                json.dump({
                    'activation_code': self.activation_code,
                    'user_id': data.get('user_id'),
                    'chat_id': data.get('chat_id'),
                    'username': data.get('username')
                }, f, indent=2)
            print("✓ Activation saved locally")
        except Exception as e:
            print(f"Warning: Failed to save activation: {e}")

    def request_activation(self):
        """Запрос кода активации у пользователя"""
        print("\n📱 Чтобы получить код активации:")
        print("1. Откройте Telegram бота: @OdaMainBot")
        print("2. Отправьте команду /start")
        print("3. Купите подписку через /buy")
        print("4. Получите код активации после оплаты")
        print("\n" + "-" * 60)

        while True:
            code = input("\nВведите код активации: ").strip().upper()

            if not code:
                print("❌ Код не может быть пустым")
                continue

            self.activation_code = code

            if self.verify_activation():
                break
            else:
                retry = input("\nПопробовать другой код? (y/n): ").strip().lower()
                if retry != 'y':
                    sys.exit(1)

    def verify_activation(self) -> bool:
        """Проверка кода на сервере"""
        print(f"\n🔄 Checking activation code...")

        try:
            # Собираем информацию о системе
            device_info = {
                'platform': platform.system(),
                'release': platform.release(),
                'version': platform.version(),
                'machine': platform.machine(),
                'processor': platform.processor(),
            }

            response = requests.post(
                f"{API_BASE_URL}/check_activation.php",
                json={
                    'activation_code': self.activation_code,
                    'device_info': json.dumps(device_info)
                },
                timeout=15
            )

            if response.status_code == 200:
                data = response.json()

                if data.get('success'):
                    result = data.get('data', {})

                    self.user_id = result.get('user_id')
                    self.chat_id = result.get('chat_id')

                    # Сохраняем локально
                    self.save_activation(result)

                    print("\n" + "=" * 60)
                    print("✓ ACTIVATION SUCCESSFUL")
                    print("=" * 60)
                    print(f"User ID: {self.user_id}")
                    print(f"Username: @{result.get('username', 'N/A')}")
                    print(f"Chat ID: {self.chat_id}")

                    if result.get('subscription_active'):
                        expires = result.get('expires_at', 'N/A')
                        print(f"Subscription: ✓ Active until {expires}")
                    else:
                        print("Subscription: ⚠️  NOT ACTIVE")
                        print("\nℹ️  Purchase subscription in Telegram bot: /buy")

                    print("=" * 60)
                    return True
                else:
                    print(f"\n❌ Activation failed: {data.get('error', 'Unknown error')}")

            elif response.status_code == 404:
                print("\n❌ Invalid activation code")
                print("Please check your code and try again")

            elif response.status_code == 409:
                print("\n❌ This code is already used on another device")
                print("Contact support if you need to reset")

            elif response.status_code == 403:
                print("\n❌ Account blocked")
                print("Contact support: @tamada_support")

            else:
                error_data = response.json() if response.text else {}
                print(f"\n❌ Server error ({response.status_code})")
                print(f"Details: {error_data.get('error', 'Unknown')}")

        except requests.exceptions.Timeout:
            print("\n❌ Connection timeout - server is not responding")

        except requests.exceptions.ConnectionError:
            print("\n❌ Cannot connect to server")
            print("Check your internet connection")

        except Exception as e:
            print(f"\n❌ Error: {e}")

        return False

    # ------------------------------------------
    # СКРИНШОТ И ОБРАБОТКА
    # ------------------------------------------

    def capture_screenshot(self) -> Optional[str]:
        """Захват скриншота и конвертация в base64"""
        try:
            with mss() as sct:
                # Захватываем первый монитор
                monitor = sct.monitors[1]
                screenshot = sct.grab(monitor)

                # Конвертируем в PNG
                png_bytes = tools.to_png(screenshot.rgb, screenshot.size)

                # Если не bytes, сохраняем во временный файл
                if not isinstance(png_bytes, (bytes, bytearray)):
                    fd, tmp_path = tempfile.mkstemp(suffix=".png")
                    try:
                        os.close(fd)
                        tools.to_png(screenshot.rgb, screenshot.size, output=tmp_path)
                        with open(tmp_path, "rb") as f:
                            png_bytes = f.read()
                    finally:
                        try:
                            os.remove(tmp_path)
                        except:
                            pass

                # Конвертируем в base64
                base64_image = base64.b64encode(png_bytes).decode('ascii')

                print(f"✓ Screenshot captured ({len(png_bytes)} bytes)")
                return base64_image

        except Exception as e:
            print(f"❌ Screenshot capture failed: {e}")
            return None

    def send_screenshot(self, base64_image: str) -> bool:
        """Отправка скриншота на сервер для обработки"""
        try:
            print("📤 Sending to server...")

            response = requests.post(
                f"{API_BASE_URL}/process_screenshot.php",
                json={
                    'activation_code': self.activation_code,
                    'screenshot': base64_image
                },
                timeout=120  # Groq API может занять время
            )

            if response.status_code == 200:
                data = response.json()

                if data.get('success'):
                    result = data.get('data', {})

                    print("\n" + "=" * 60)
                    print("✓ PROCESSING SUCCESSFUL")
                    print("=" * 60)
                    print(f"Answer sent to your Telegram")
                    print(f"Response time: {result.get('response_time_ms', 0)}ms")
                    print("=" * 60 + "\n")

                    return True
                else:
                    error = data.get('error', 'Unknown error')
                    print(f"\n❌ Server error: {error}")

                    # Специальные обработки ошибок
                    if 'expired' in error.lower() or 'subscription' in error.lower():
                        print("\n⚠️  Your subscription has expired!")
                        print("Renew in Telegram bot: /buy")

                    elif 'blocked' in error.lower():
                        print("\n⚠️  Your account is blocked")
                        print("Contact support: @tamada_support")

                    elif 'rate limit' in error.lower() or 'too many' in error.lower():
                        print("\n⚠️  Too many requests")
                        print("Please wait a moment and try again")

            elif response.status_code == 401:
                print("\n❌ Invalid activation code")
                print("Your activation may have been revoked")

            elif response.status_code == 402:
                print("\n❌ Subscription expired")
                print("Renew in Telegram: /buy")

            elif response.status_code == 403:
                print("\n❌ Account blocked")

            elif response.status_code == 429:
                print("\n❌ Rate limit exceeded")
                print("Please wait before sending another screenshot")

            else:
                print(f"\n❌ HTTP error: {response.status_code}")

            return False

        except requests.exceptions.Timeout:
            print("\n❌ Request timeout")
            print("Server is processing, check Telegram for answer")
            return False

        except requests.exceptions.ConnectionError:
            print("\n❌ Connection error")
            print("Check your internet connection")
            return False

        except Exception as e:
            print(f"\n❌ Error: {e}")
            return False

    # ------------------------------------------
    # ОБРАБОТКА ГОРЯЧИХ КЛАВИШ
    # ------------------------------------------

    def on_hotkey_pressed(self):
        """Обработка нажатия Ctrl+Shift+X"""
        print("\n" + "=" * 60)
        print("🔥 HOTKEY TRIGGERED")
        print("=" * 60)

        # Захватываем скриншот
        base64_image = self.capture_screenshot()

        if not base64_image:
            print("❌ Failed to capture screenshot")
            return

        # Отправляем на сервер
        self.send_screenshot(base64_image)

    def on_key_press(self, key):
        """Отслеживание нажатых клавиш"""
        try:
            self._pressed_keys.add(key)

            # Проверяем комбинацию Ctrl+Shift+X
            ctrl = any(k in self._pressed_keys for k in [
                keyboard.Key.ctrl_l, keyboard.Key.ctrl_r, keyboard.Key.ctrl
            ])

            shift = any(k in self._pressed_keys for k in [
                keyboard.Key.shift_l, keyboard.Key.shift_r, keyboard.Key.shift
            ])

            x_pressed = False
            try:
                if hasattr(key, 'char') and key.char and key.char.lower() == 'x':
                    x_pressed = True
            except AttributeError:
                pass

            # Если все три клавиши нажаты
            if ctrl and shift and x_pressed:
                # Запускаем в отдельном потоке
                threading.Thread(
                    target=self.on_hotkey_pressed,
                    daemon=True
                ).start()

        except Exception as e:
            print(f"Key press error: {e}")

    def on_key_release(self, key):
        """Отслеживание отпущенных клавиш"""
        try:
            self._pressed_keys.discard(key)
        except:
            pass

    # ------------------------------------------
    # ЗАПУСК
    # ------------------------------------------

    def run(self):
        """Запуск клиента"""
        print("\n" + "=" * 60)
        print("✓ QuickVision Client is Running")
        print("=" * 60)
        print("📸 Press Ctrl+Shift+X to capture screenshot")
        print("📱 Answers will be sent to your Telegram")
        print("⌨️  Press Ctrl+C to exit")
        print("=" * 60 + "\n")

        # Запускаем listener клавиатуры
        with keyboard.Listener(
                on_press=self.on_key_press,
                on_release=self.on_key_release
        ) as listener:
            try:
                listener.join()
            except KeyboardInterrupt:
                print("\n\n👋 Shutting down...")
                self.running = False


# ==============================================
# ТОЧКА ВХОДА
# ==============================================
def main():
    """Главная функция"""
    try:
        client = QuickVisionClient()
        client.run()

    except KeyboardInterrupt:
        print("\n\n👋 Exiting...")

    except Exception as e:
        print(f"\n💥 Fatal error: {e}")
        import traceback
        traceback.print_exc()
        input("\nPress Enter to exit...")


if __name__ == "__main__":
    main()