import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.*;
import java.security.spec.ECGenParameterSpec;
import java.util.*;

/** Generates public, synthetic interoperability evidence. Private key remains in memory. */
class GenerateFixture {
    static String quote(String s) { return "\"" + s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n") + "\""; }
    static String hash(byte[] b) throws Exception { return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(b)); }
    public static void main(String[] args) throws Exception {
        var generator = KeyPairGenerator.getInstance("EC"); generator.initialize(new ECGenParameterSpec("secp256r1"));
        var key = generator.generateKeyPair();
        var body = "{\"observed_at\":\"2026-10-25T10:00:00Z\",\"sms_listener_healthy\":false,\"notification_listener_healthy\":false,\"app_version\":\"interop-₹\"}";
        var timestamp = "1792922400";
        var nonce = "ab".repeat(32);
        var path = "/api/device/v1/heartbeat";
        var bodyHash = hash(body.getBytes(StandardCharsets.UTF_8));
        var canonical = String.join("\n", "POST", path, timestamp, nonce, bodyHash);
        var signature = Signature.getInstance("SHA256withECDSA"); signature.initSign(key.getPrivate()); signature.update(canonical.getBytes(StandardCharsets.UTF_8));
        var values = new LinkedHashMap<String, String>();
        values.put("body", body); values.put("path", path); values.put("timestamp", timestamp); values.put("nonce", nonce);
        values.put("body_sha256", bodyHash); values.put("canonical", canonical);
        values.put("public_key_der", Base64.getEncoder().encodeToString(key.getPublic().getEncoded()));
        values.put("signature", Base64.getEncoder().encodeToString(signature.sign()));
        values.put("pairing_token", "a".repeat(48)); values.put("challenge_sha256", hash(("hx-pair-v1\n" + "a".repeat(48)).getBytes(StandardCharsets.UTF_8)));
        Files.writeString(Path.of(args[0]), "{\n" + String.join(",\n", values.entrySet().stream().map(e -> "  " + quote(e.getKey()) + ": " + quote(e.getValue())).toList()) + "\n}\n");
    }
}
