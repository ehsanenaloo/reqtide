<?php

declare(strict_types=1);

/**
 * Writes a throwaway CA, a server certificate signed by it and a second, unrelated CA into the directory given as the
 * first argument (default /tls). For the TLS lane only (compose profile "redis-tls"); nothing here is committed, and the
 * files live in a Docker volume that `docker compose down -v` removes.
 *
 *   ca.crt, server.crt, server.key   the CA the tests trust and the server certificate (names: localhost, redis74-tls,
 *                                    redis82-tls, http-fixture-tls; no IP address, so 127.0.0.1 fails name verification)
 *   other-ca.crt                     a CA that did not sign the server certificate (the "wrong CA" case)
 */

$directory = $argv[1] ?? '/tls';
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    fwrite(STDERR, "cannot create $directory\n");
    exit(1);
}

$config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

function make_ca(string $name, array $config): array
{
    $key = openssl_pkey_new($config);
    $csr = openssl_csr_new(['commonName' => $name], $key, $config);
    $cert = openssl_csr_sign($csr, null, $key, 2, $config + ['x509_extensions' => 'v3_ca'], random_int(1, 1_000_000));

    return [$cert, $key];
}

[$caCert, $caKey] = make_ca('Reqtide test CA', $config);
[$otherCert] = make_ca('Reqtide other CA', $config);

$serverKey = openssl_pkey_new($config);
$serverCsr = openssl_csr_new(['commonName' => 'localhost'], $serverKey, $config);
$extensions = tempnam(sys_get_temp_dir(), 'ext');
file_put_contents($extensions, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:localhost,DNS:redis74-tls,DNS:redis82-tls,DNS:http-fixture-tls\nbasicConstraints=CA:FALSE\n");
$serverCert = openssl_csr_sign($serverCsr, $caCert, $caKey, 2, $config + ['config' => $extensions, 'x509_extensions' => 'v3'], random_int(1, 1_000_000));
unlink($extensions);

foreach ([$caCert, $serverCert, $otherCert] as $cert) {
    if ($cert === false) {
        fwrite(STDERR, "certificate generation failed: " . openssl_error_string() . "\n");
        exit(1);
    }
}
openssl_x509_export($caCert, $caPem);
openssl_x509_export($serverCert, $serverPem);
openssl_x509_export($otherCert, $otherPem);
openssl_pkey_export($serverKey, $serverKeyPem);

file_put_contents("$directory/ca.crt", $caPem);
file_put_contents("$directory/server.crt", $serverPem);
file_put_contents("$directory/server.key", $serverKeyPem);
file_put_contents("$directory/other-ca.crt", $otherPem);
foreach (['ca.crt', 'server.crt', 'server.key', 'other-ca.crt'] as $file) {
    chmod("$directory/$file", 0644);
}
echo "wrote test certificates to $directory\n";
