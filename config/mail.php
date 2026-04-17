<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

const MAILTRAP_HOST = 'sandbox.smtp.mailtrap.io';
const MAILTRAP_PORT = 587;

// Laptop Asus 
// const MAILTRAP_USERNAME = '952078ef4bf7c9';
// const MAILTRAP_PASSWORD = '0786349e6f8e62';

// Laptop Magang 
const MAILTRAP_USERNAME = '09448579e2b7ca';
const MAILTRAP_PASSWORD = 'e64014c4fbb9e1';

const EMAIL_BRANCH_INTI = 'branch.Jakarta@ithexindo.test';
const NAMA_BRANCH_INTI  = 'Jakarta';

function kirimEmailKeBranchInti(array $data): array
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAILTRAP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAILTRAP_USERNAME;
        $mail->Password   = MAILTRAP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAILTRAP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('noreply@ithexindo.test', 'IT Asset Management');
        $mail->addAddress(EMAIL_BRANCH_INTI, NAMA_BRANCH_INTI);
        $mail->addReplyTo('noreply@ithexindo.test', 'No Reply');

        $e = fn($v) => htmlspecialchars((string)($v ?? '-'), ENT_QUOTES, 'UTF-8');

        $branch        = $e($data['branch'] ?? '-');
        $noAsset       = $e($data['no_asset'] ?? '-');
        $serialNumber  = $e($data['serial_number'] ?? '-');
        $namaBarang    = $e($data['nama_barang'] ?? '-');
        $merk          = $e($data['merk'] ?? '-');
        $tipe          = $e($data['tipe'] ?? '-');
        $jenis         = $e($data['jenis'] ?? '-');
        $tanggalMasuk  = $e($data['tanggal_masuk'] ?? '-');
        $userPenginput = $e($data['user'] ?? '-');
        $bermasalah    = $e($data['bermasalah'] ?? '-');
        $keterangan    = $e($data['keterangan_masalah'] ?? '-');

        $isBermasalah = (($data['bermasalah'] ?? '') === 'Iya');

        $badgeBg   = $isBermasalah
            ? 'linear-gradient(135deg, #ff7a00, #ff9f1a)'
            : 'linear-gradient(135deg, #111111, #2e2e2e)';
        $badgeText = $isBermasalah ? 'Bermasalah' : 'Normal';

        $fotoHtml = '
    <tr>
        <td style="padding:24px 28px 0 28px;">
            <div style="background:#fffaf3;border:1px dashed #f1c27b;border-radius:18px;padding:22px;text-align:center;color:#8a6a35;font-size:14px;">
                Foto barang tidak tersedia.
            </div>
        </td>
    </tr>
';

        if (!empty($data['foto_path']) && file_exists($data['foto_path'])) {
            $mail->addEmbeddedImage($data['foto_path'], 'foto_barang_cid');

            $fotoHtml = '
        <tr>
            <td style="padding:24px 28px 0 28px;">
                <div style="background:#fffaf3;border:1px solid rgba(255,152,0,0.18);border-radius:20px;padding:18px;">
                    <div style="font-size:15px;font-weight:800;color:#1f1f1f;margin-bottom:12px;">Foto Barang</div>
                    <img src="cid:foto_barang_cid" alt="Foto Barang" style="display:block;width:100%;max-width:520px;height:auto;border-radius:16px;border:1px solid #ead9bf;margin:0 auto;">
                </div>
            </td>
        </tr>
    ';
        }

        $keteranganHtml = '';
        if ($isBermasalah) {
            $keteranganHtml = '
        <tr>
            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;width:38%;color:#6b7280;">Keterangan Masalah</td>
            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:600;">' . $keterangan . '</td>
        </tr>
    ';
        }

        $mail->isHTML(true);
        $mail->Subject = 'Notifikasi Barang Masuk dari ' . ($data['branch'] ?? '-');

        $mail->Body = '
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi Barang</title>
</head>
<body style="margin:0;padding:0;background:#fff8f1;font-family:Segoe UI,Arial,sans-serif;color:#1f1f1f;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fff8f1;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:28px 14px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:680px;background:#ffffff;border-radius:28px;overflow:hidden;border:1px solid rgba(255,152,0,0.14);box-shadow:0 12px 36px rgba(17,17,17,0.07);">

                    <tr>
                        <td style="padding:0;">
                            <div style="background:linear-gradient(135deg,#111111 0%,#2c2c2c 40%,#ff8f00 100%);padding:30px 28px;border-radius:28px 28px 0 0;">
                                <div style="font-size:28px;line-height:1.2;font-weight:800;color:#ffffff;margin-bottom:8px;">
                                    Notifikasi Pengiriman Barang
                                </div>
                                <div style="font-size:14px;line-height:1.7;color:rgba(255,255,255,0.88);max-width:540px;">
                                    Ada input barang baru dari branch lain yang perlu diperhatikan oleh branch inti.
                                </div>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 28px 0 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="50%" style="padding-right:8px;padding-bottom:12px;">
                                        <div style="background:linear-gradient(180deg,#ffffff 0%,#fffaf3 100%);border:1px solid rgba(255,176,0,0.15);border-radius:20px;padding:18px;">
                                            <div style="font-size:12px;color:#6b7280;font-weight:700;margin-bottom:6px;">Branch Pengirim</div>
                                            <div style="font-size:20px;font-weight:800;color:#111111;">' . $branch . '</div>
                                        </div>
                                    </td>
                                    <td width="50%" style="padding-left:8px;padding-bottom:12px;">
                                        <div style="background:linear-gradient(180deg,#ffffff 0%,#fffaf3 100%);border:1px solid rgba(255,176,0,0.15);border-radius:20px;padding:18px;">
                                            <div style="font-size:12px;color:#6b7280;font-weight:700;margin-bottom:6px;">Status Barang</div>
                                            <div style="display:inline-block;padding:9px 14px;border-radius:999px;background:' . $badgeBg . ';color:#ffffff;font-size:13px;font-weight:800;">
                                                ' . $badgeText . '
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:12px 28px 0 28px;">
                            <div style="background:#ffffff;border:1px solid rgba(255,152,0,0.14);border-radius:22px;overflow:hidden;">
                                <div style="background:linear-gradient(135deg,#111111 0%,#2c2c2c 40%,#ff8f00 100%);padding:14px 18px;color:#ffffff;font-size:16px;font-weight:800;">
                                    Detail Barang
                                </div>
                                <div style="padding:18px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;line-height:1.6;">
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;width:38%;color:#6b7280;">No Asset</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $noAsset . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Serial Number</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $serialNumber . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Barang</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $namaBarang . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Merk</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $merk . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Tipe</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $tipe . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Jenis</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $jenis . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Tanggal Masuk</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $tanggalMasuk . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">User</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $userPenginput . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#6b7280;">Bermasalah</td>
                                            <td style="padding:12px 0;border-bottom:1px solid #f2ede5;color:#1f1f1f;font-weight:700;">' . $bermasalah . '</td>
                                        </tr>
                                        ' . $keteranganHtml . '
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>

                    ' . $fotoHtml . '

                    <tr>
                        <td style="padding:24px 28px 28px 28px;">
                            <div style="background:#fffaf3;border:1px solid rgba(255,152,0,0.14);border-radius:18px;padding:16px 18px;color:#6b7280;font-size:13px;line-height:1.7;">
                                Email ini dikirim otomatis oleh <b style="color:#111111;">IT Asset Management System</b> untuk notifikasi barang masuk dari branch lain ke branch inti.
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
';

        $mail->AltBody =
            "Notifikasi Barang Masuk\n" .
            "Branch Pengirim: " . ($data['branch'] ?? '-') . "\n" .
            "No Asset: " . ($data['no_asset'] ?? '-') . "\n" .
            "Serial Number: " . ($data['serial_number'] ?? '-') . "\n" .
            "Barang: " . ($data['nama_barang'] ?? '-') . "\n" .
            "Merk: " . ($data['merk'] ?? '-') . "\n" .
            "Tipe: " . ($data['tipe'] ?? '-') . "\n" .
            "Jenis: " . ($data['jenis'] ?? '-') . "\n" .
            "Tanggal Masuk: " . ($data['tanggal_masuk'] ?? '-') . "\n" .
            "User: " . ($data['user'] ?? '-') . "\n" .
            "Bermasalah: " . ($data['bermasalah'] ?? '-');
        $mail->send();

        return [
            'status'  => true,
            'message' => 'Email notifikasi berhasil dikirim.'
        ];
    } catch (Exception $e) {
        return [
            'status'  => false,
            'message' => $mail->ErrorInfo ?: $e->getMessage()
        ];
    }
}
