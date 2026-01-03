#!/usr/bin/env python3
"""
QuickVision Client
Упрощенная версия - только захват экрана и отправка на сервер
Вся логика обработки на сервере
"""

import os
import sys
import json
import base64
import tempfile
import platform
import threading
import requests
from typing import Optional

try:
    from mss import mss, tools
    from pynput import keyboard
except ImportError:
    print("Error: Required libraries not installed")
    print("Please install: pip install mss pynput requests")
    sys.exit(1)

# Конфигурация
API_URL = "https://tamada-games.lol/api"
ACTIVATION_FILE = "activation.dat"


class QuickVisionClient:
    """Клиент QuickVision - только захват и отправка"""
    
    def __init__(self):
        self.activation_code: Optional[str] = None
        self.user_id: Optional[int] = None
        self.running = True
        self._pressed_keys = set()
        
        # Загружаем сохраненный код активации
        self.load_activation()
        
        # Если нет кода - запрашиваем
        if not self.activation_code:
            self.request_activation_code()
        else:
            print(f"Loaded activation code: {self.activation_code[:8]}...")
            self.verify_activation()
    
    def load_activation(self):
        """Загрузка сохраненного кода активации"""
        try:
            if os.path.exists(ACTIVATION_FILE):
                with open(ACTIVATION_FILE, 'r') as f:
                    data = json.load(f)
                    self.activation_code = data.get('activation_code')
                    self.user_id = data.get('user_id')
        except Exception as e:
            print(f"Failed to load activation: {e}")
    
    def save_activation(self):
        """Сохранение кода активации"""
        try:
            with open(ACTIVATION_FILE, 'w') as f:
                json.dump({
                    'activation_code': self.activation_code,
                    'user_id': self.user_id
                }, f)
        except Exception as e:
            print(f"Failed to save activation: {e}")
    
    def request_activation_code(self):
        """Запрос кода активации у пользователя"""
        print("\n" + "="*50)
        print("QuickVision Client")
        print("="*50)
        print("\nВведите код активации (получен в Telegram боте):")
        
        code = input("> ").strip().upper()
        
        if not code:
            print("Error: Activation code cannot be empty")
            sys.exit(1)
        
        self.activation_code = code
        self.verify_activation()
    
    def verify_activation(self):
        """Проверка кода активации на сервере"""
        print(f"\nVerifying activation code...")
        
        try:
            # Собираем информацию о системе
            device_info = {
                'platform': platform.system(),
                'platform_release': platform.release(),
                'platform_version': platform.version(),
                'architecture': platform.machine(),
                'processor': platform.processor(),
                'python_version': platform.python_version()
            }
            
            response = requests.post(
                f"{API_URL}/check_activation.php",
                json={
                    'activation_code': self.activation_code,
                    'device_info': json.dumps(device_info)
                },
                timeout=10
            )
            
            if response.status_code == 200:
                data = response.json()
                
                if data.get('success'):
                    self.user_id = data['data']['user_id']
                    self.save_activation()
                    
                    print("✓ Activation successful!")
                    print(f"  User ID: {self.user_id}")
                    print(f"  Username: @{data['data'].get('username', 'N/A')}")
                    
                    if data['data']['subscription_active']:
                        expires = data['data'].get('expires_at')
                        print(f"  Subscription: Active until {expires}")
                    else:
                        print("  Subscription: Inactive - please purchase")
                    
                    print("\n✓ Ready to use! Press Ctrl+Shift+X to capture screenshot")
                    return True
                else:
                    print(f"✗ Activation failed: {data.get('error', 'Unknown error')}")
            else:
                print(f"✗ Server error: {response.status_code}")
                try:
                    error_data = response.json()
                    print(f"  Error: {error_data.get('error', 'Unknown')}")
                except:
                    pass
        
        except requests.exceptions.RequestException as e:
            print(f"✗ Network error: {e}")
        except Exception as e:
            print(f"✗ Error: {e}")
        
        print("\nPlease check your activation code and try again")
        sys.exit(1)
    
    def capture_screenshot(self) -> Optional[str]:
        """Захват скриншота и конвертация в base64"""
        try:
            with mss() as sct:
                monitor = sct.monitors[1]  # Первый монитор
                screenshot = sct.grab(monitor)
                
                # Конвертируем в PNG bytes
                png_bytes = tools.to_png(screenshot.rgb, screenshot.size)
                
                # Если это не bytes, сохраняем во временный файл
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
                
                print(f"Screenshot captured: {len(png_bytes)} bytes")
                return base64_image
                
        except Exception as e:
            print(f"Screenshot capture error: {e}")
            return None
    
    def send_screenshot(self, base64_image: str) -> bool:
        """Отправка скриншота на сервер"""
        try:
            print("Sending to server...")
            
            response = requests.post(
                f"{API_URL}/process_screenshot.php",
                json={
                    'activation_code': self.activation_code,
                    'screenshot': base64_image
                },
                timeout=90  # Groq может занять время
            )
            
            if response.status_code == 200:
                data = response.json()
                
                if data.get('success'):
                    print("✓ Screenshot processed successfully")
                    print(f"  Answer: {data['data'].get('answer', 'N/A')}")
                    print(f"  Response time: {data['data'].get('response_time_ms', 0)}ms")
                    print(f"  Telegram sent: {data['data'].get('telegram_sent', False)}")
                    return True
                else:
                    print(f"✗ Server error: {data.get('error', 'Unknown')}")
                    
                    # Специальные ошибки
                    error = data.get('error', '')
                    if 'expired' in error.lower():
                        print("\n⚠️  Your subscription has expired!")
                        print("Please renew in Telegram bot: /buy")
                    elif 'blocked' in error.lower():
                        print("\n⚠️  Your account has been blocked")
                        print("Please contact support")
            else:
                print(f"✗ HTTP error: {response.status_code}")
                try:
                    error_data = response.json()
                    print(f"  Details: {error_data.get('error', 'Unknown')}")
                except:
                    pass
            
            return False
            
        except requests.exceptions.Timeout:
            print("✗ Request timeout (server is processing...)")
            return False
        except requests.exceptions.RequestException as e:
            print(f"✗ Network error: {e}")
            return False
        except Exception as e:
            print(f"✗ Error: {e}")
            return False
    
    def on_hotkey_pressed(self):
        """Обработка нажатия горячей клавиши"""
        print("\n" + "="*50)
        print("Hotkey pressed! Processing...")
        print("="*50)
        
        # Захватываем скриншот
        base64_image = self.capture_screenshot()
        
        if not base64_image:
            print("✗ Failed to capture screenshot")
            return
        
        # Отправляем на сервер
        self.send_screenshot(base64_image)
        
        print("="*50 + "\n")
    
    def on_key_press(self, key):
        """Отслеживание нажатых клавиш"""
        try:
            self._pressed_keys.add(key)
            
            # Проверяем комбинацию Ctrl+Shift+X
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
            
            # Если все три клавиши нажаты
            if ctrl_pressed and shift_pressed and x_pressed:
                # Запускаем в отдельном потоке чтобы не блокировать listener
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
    
    def run(self):
        """Запуск клиента"""
        print("\n" + "="*50)
        print("QuickVision Client Running")
        print("="*50)
        print("Press Ctrl+Shift+X to capture screenshot")
        print("Press Ctrl+C to exit")
        print("="*50 + "\n")
        
        # Запускаем listener клавиатуры
        with keyboard.Listener(
            on_press=self.on_key_press,
            on_release=self.on_key_release
        ) as listener:
            try:
                listener.join()
            except KeyboardInterrupt:
                print("\n\nShutting down...")
                self.running = False


def main():
    """Точка входа"""
    try:
        client = QuickVisionClient()
        client.run()
    except KeyboardInterrupt:
        print("\n\nExiting...")
    except Exception as e:
        print(f"\nFatal error: {e}")
        import traceback
        traceback.print_exc()


if __name__ == "__main__":
    main()