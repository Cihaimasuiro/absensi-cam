"""
Clean room implementation of AES-256-GCM encryption for backups.
"""

import hashlib
import hmac
import logging
import os
import platform
import base64
from pathlib import Path

from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from config.paths import DATA_DIR

logger = logging.getLogger(__name__)

SALT_SIZE = 16
IV_SIZE = 12
KEY_SIZE = 32
PBKDF2_ITERS = 480_000
FACENOX_MAGIC = b"FACENOX\x00\x01"

_CACHED_MACHINE_KEY: bytes | None = None

class DecryptionError(Exception):
    pass

def _derive_key(password: str, salt: bytes) -> bytes:
    return hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, PBKDF2_ITERS, dklen=KEY_SIZE)

def encrypt_backup(plaintext: bytes, password: str) -> bytes:
    salt = os.urandom(SALT_SIZE)
    iv = os.urandom(IV_SIZE)
    key = _derive_key(password, salt)
    aesgcm = AESGCM(key)
    ciphertext = aesgcm.encrypt(iv, plaintext, None)
    return FACENOX_MAGIC + salt + iv + ciphertext

def decrypt_backup(blob: bytes, password: str) -> bytes:
    magic_len = len(FACENOX_MAGIC)
    if len(blob) < magic_len + SALT_SIZE + IV_SIZE + 16:
        raise ValueError("Invalid backup format")
    if not hmac.compare_digest(blob[:magic_len], FACENOX_MAGIC):
        raise ValueError("Invalid backup magic")
    
    salt = blob[magic_len:magic_len+SALT_SIZE]
    iv = blob[magic_len+SALT_SIZE:magic_len+SALT_SIZE+IV_SIZE]
    ciphertext = blob[magic_len+SALT_SIZE+IV_SIZE:]
    
    key = _derive_key(password, salt)
    aesgcm = AESGCM(key)
    try:
        return aesgcm.decrypt(iv, ciphertext, None)
    except Exception as e:
        raise ValueError("Decryption failed") from e

def _get_env_key() -> bytes | None:
    env_key = os.getenv("FACENOX_MACHINE_KEY")
    if not env_key:
        return None
    try:
        return base64.b64decode(env_key.strip(), validate=True)
    except Exception:
        pass
    try:
        return bytes.fromhex(env_key.strip())
    except Exception:
        return None

def _get_os_key() -> bytes:
    system = platform.system()
    try:
        if system == "Windows":
            return _windows_key()
        elif system == "Darwin":
            return _macos_key()
        else:
            return _linux_key()
    except Exception as e:
        logger.warning(f"OS specific key retrieval failed: {e}. Falling back to file.")
        return _file_key()

def _windows_key() -> bytes:
    import ctypes
    import ctypes.wintypes
    class DATA_BLOB(ctypes.Structure):
        _fields_ = [("cbData", ctypes.wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_byte))]
    
    key_path = DATA_DIR / ".machine_key.dpapi"
    crypt32 = ctypes.windll.crypt32

    if key_path.exists():
        with open(key_path, "rb") as f:
            encrypted = f.read()
        enc_array = (ctypes.c_byte * len(encrypted)).from_buffer_copy(encrypted)
        enc_blob = DATA_BLOB(len(encrypted), enc_array)
        dec_blob = DATA_BLOB()
        if not crypt32.CryptUnprotectData(ctypes.byref(enc_blob), None, None, None, None, 0, ctypes.byref(dec_blob)):
            raise RuntimeError("DPAPI decrypt failed")
        key = bytes(ctypes.string_at(dec_blob.pbData, dec_blob.cbData))
        ctypes.windll.kernel32.LocalFree(dec_blob.pbData)
        return key

    raw_key = os.urandom(KEY_SIZE)
    raw_array = (ctypes.c_byte * KEY_SIZE).from_buffer_copy(raw_key)
    raw_blob = DATA_BLOB(KEY_SIZE, raw_array)
    enc_blob = DATA_BLOB()
    if not crypt32.CryptProtectData(ctypes.byref(raw_blob), None, None, None, None, 0, ctypes.byref(enc_blob)):
        raise RuntimeError("DPAPI encrypt failed")
    encrypted = bytes(ctypes.string_at(enc_blob.pbData, enc_blob.cbData))
    ctypes.windll.kernel32.LocalFree(enc_blob.pbData)
    
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    with open(key_path, "wb") as f:
        f.write(encrypted)
    return raw_key

def _macos_key() -> bytes:
    import keyring
    service = "facenox-biometric-key"
    account = "machine-key"
    password = keyring.get_password(service, account)
    if password:
        return base64.b64decode(password.strip())
    raw_key = os.urandom(KEY_SIZE)
    keyring.set_password(service, account, base64.b64encode(raw_key).decode())
    return raw_key

def _linux_key() -> bytes:
    if not os.environ.get("DBUS_SESSION_BUS_ADDRESS"):
        raise RuntimeError("No DBUS session")
    import keyring
    from keyring.backends.fail import Keyring as FailKeyring
    if isinstance(keyring.get_keyring(), FailKeyring):
        raise RuntimeError("No keyring available")
    
    service = "facenox-biometric-key"
    account = "machine-key"
    password = keyring.get_password(service, account)
    if password:
        return base64.b64decode(password.strip())
    raw_key = os.urandom(KEY_SIZE)
    keyring.set_password(service, account, base64.b64encode(raw_key).decode())
    return raw_key

def _file_key() -> bytes:
    key_path = DATA_DIR / ".machine_key"
    if key_path.exists():
        with open(key_path, "rb") as f:
            return f.read()
    key = os.urandom(KEY_SIZE)
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    with open(key_path, "wb") as f:
        f.write(key)
    try:
        os.chmod(str(key_path), 0o600)
    except Exception:
        pass
    return key

def get_machine_key() -> bytes:
    global _CACHED_MACHINE_KEY
    if _CACHED_MACHINE_KEY is not None:
        return _CACHED_MACHINE_KEY

    env_key = _get_env_key()
    if env_key and len(env_key) == KEY_SIZE:
        _CACHED_MACHINE_KEY = env_key
        return env_key

    key = _get_os_key()
    _CACHED_MACHINE_KEY = key
    return key

def encrypt_local_data(plaintext: bytes) -> bytes:
    key = get_machine_key()
    iv = os.urandom(IV_SIZE)
    aesgcm = AESGCM(key)
    ciphertext = aesgcm.encrypt(iv, plaintext, None)
    return iv + ciphertext

def decrypt_local_data(blob: bytes) -> bytes:
    key = get_machine_key()
    if len(blob) < IV_SIZE:
        raise DecryptionError("Blob too short")
    iv = blob[:IV_SIZE]
    ciphertext = blob[IV_SIZE:]
    aesgcm = AESGCM(key)
    try:
        return aesgcm.decrypt(iv, ciphertext, None)
    except Exception as e:
        raise DecryptionError("Decryption failed") from e
