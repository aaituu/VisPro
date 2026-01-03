#!/usr/bin/env python3
"""
Build QuickVision executable using PyInstaller
"""

import os
import sys
import subprocess

def build_exe():
    """Build executable for current platform"""
    
    print("Building QuickVision executable...")
    
    # Определяем платформу
    if sys.platform.startswith('win'):
        icon = 'icon.ico'
        name = 'QuickVision.exe'
    elif sys.platform == 'darwin':
        icon = 'icon.icns'
        name = 'QuickVision'
    else:
        icon = None
        name = 'QuickVision'
    
    # Команда PyInstaller
    cmd = [
        'pyinstaller',
        '--onefile',
        '--windowed',  # Без консоли
        '--name', 'QuickVision',
        '--clean',
    ]
    
    if icon and os.path.exists(icon):
        cmd.extend(['--icon', icon])
    
    # Добавляем скрипт
    cmd.append('quickvision_client.py')
    
    # Запускаем
    result = subprocess.run(cmd, capture_output=True, text=True)
    
    if result.returncode == 0:
        print(f"✓ Build successful!")
        print(f"  Executable: dist/{name}")
    else:
        print(f"✗ Build failed!")
        print(result.stderr)
        return False
    
    return True

if __name__ == "__main__":
    # Проверяем PyInstaller
    try:
        import PyInstaller
    except ImportError:
        print("Error: PyInstaller not found")
        print("Install it with: pip install pyinstaller")
        sys.exit(1)
    
    build_exe()