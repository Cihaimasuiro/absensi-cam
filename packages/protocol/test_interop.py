import base64
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
import sys

def decrypt_blob(b64_blob, key_str, aad_str):
    encrypted_blob = base64.b64decode(b64_blob)
    key = key_str.encode('utf-8')
    aad = aad_str.encode('utf-8')
    
    if len(encrypted_blob) != 2076:
        raise ValueError(f"Expected 2076 bytes, got {len(encrypted_blob)}")
        
    nonce = encrypted_blob[:12]
    ciphertext_and_tag = encrypted_blob[12:]
    
    aesgcm = AESGCM(key)
    plaintext = aesgcm.decrypt(nonce, ciphertext_and_tag, aad)
    return plaintext

if __name__ == '__main__':
    b64_input = sys.stdin.read().strip()
    plaintext = decrypt_blob(b64_input, 'A' * 32, 'user123_sface-2021dec')
    if plaintext == b'\x00' * 2048:
        print("SUCCESS: Python decrypted PHP AES-256-GCM blob perfectly!")
    else:
        print("FAIL: Decrypted content does not match!")
