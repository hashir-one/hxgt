<!doctype html>
<html lang="en">
<body style="margin:0;background:#07111f;color:#dbeafe;font-family:Arial,Helvetica,sans-serif;">
<div style="max-width:560px;margin:0 auto;padding:40px 20px;">
    <div style="border:1px solid #243b5a;border-radius:24px;background:#0d1b2f;padding:32px;">
        <div style="display:inline-block;border-radius:12px;background:#67e8f9;color:#07111f;font-weight:800;padding:10px 12px;">HX</div>
        <p style="margin:24px 0 8px;color:#67e8f9;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">HX UPI Gateway</p>
        <h1 style="margin:0;color:#fff;font-size:28px;line-height:1.2;">Your verification code</h1>
        <p style="color:#94a3b8;font-size:15px;line-height:1.6;">Use this one-time code to {{ $purpose === 'manual_confirm' ? 'authorize a manual payment confirmation' : 'open the operations console' }}.</p>
        <div style="margin:28px 0;padding:20px;border:1px solid #164e63;border-radius:16px;background:#082f49;text-align:center;">
            <div style="color:#cffafe;font-size:36px;font-weight:800;letter-spacing:10px;">{{ $code }}</div>
        </div>
        <p style="color:#94a3b8;font-size:13px;line-height:1.6;">This code expires in {{ $ttlMinutes }} minutes and can be used once. If you did not request it, you can safely ignore this email.</p>
        <p style="margin-top:28px;color:#475569;font-size:12px;">Security notice · HX UPI Gateway will never ask for this code over chat or phone.</p>
    </div>
</div>
</body>
</html>
