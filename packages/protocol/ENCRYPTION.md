# Encryption Protocol: Smart Absensi Face Templates

## 1. Specification
- **Algorithm**: AES-256-GCM
- **Key Length**: 256 bits (32 bytes)
- **Nonce (IV) Length**: 96 bits (12 bytes)
- **Auth Tag Length**: 128 bits (16 bytes)
- **AAD (Additional Authenticated Data)**: `member_id + model_version`

## 2. Binary Format
The final encrypted BLOB must be constructed exactly as:
`[Nonce (12 bytes)] || [Ciphertext (2048 bytes)] || [Auth Tag (16 bytes)]`
Total Length: 2076 bytes.

## 3. Key Management
- Keys must NOT be shared globally.
- `key_id` must be provided during template sync and enrollment.
- Keys are provisioned per-device or per-site over TLS during pairing.
- Edge nodes store keys in SQLite or secure storage with 0600 permissions.

## 4. Implementation Details
**PHP (Server)**
`openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);`
Returns `$ciphertext` and writes `$tag` separately. 
Format: `$nonce . $ciphertext . $tag`

**Python (Edge)**
`cryptography.hazmat.primitives.ciphers.aead.AESGCM`
`AESGCM(key).decrypt(nonce, ciphertext_and_tag, aad)`
Note: `cryptography` expects `ciphertext_and_tag` as a single concatenated string `ciphertext || tag`. Since PHP writes it at the end, it exactly matches Python's expectation.
