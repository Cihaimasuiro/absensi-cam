<?php
$key = str_repeat('A', 32);
$nonce = str_repeat('B', 12);
$aad = "user123_arcface-512";
$plaintext = str_repeat("\x00", 2048); // 2048 null bytes

$tag = '';
$ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);

$encrypted_blob = $nonce . $ciphertext . $tag;
echo base64_encode($encrypted_blob) . "\n";
