<?php
if (!function_exists('nuevoPuertaEmailCompanyInfo')) {
    function nuevoPuertaEmailCompanyInfo(): array {
        return [
            'name' => 'Nuevo Puerta Real Estate',
            'phone' => '+63 912 345 6789',
            'email' => 'nuevopuertarealestate@gmail.com',
            'address' => 'Main Road, Zamboanga City',
            'hours' => 'Mon-Sat 8AM-5PM',
            'website_label' => 'Nuevo Puerta',
        ];
    }
}

if (!function_exists('nuevoPuertaEmailEscape')) {
    function nuevoPuertaEmailEscape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('buildNovoPuertaEmailHtml')) {
    function buildNovoPuertaEmailHtml(string $heading, string $bodyHtml, array $options = []): string {
        $company = nuevoPuertaEmailCompanyInfo();
        $subjectLine = trim((string)($options['subject_line'] ?? $heading));
        $intro = trim((string)($options['intro'] ?? ''));
        $footerNote = trim((string)($options['footer_note'] ?? ''));
        $accent = (string)($options['accent'] ?? '#14532d');
        $subaccent = (string)($options['subaccent'] ?? '#166534');

        $headerHtml = '<tr><td style="padding:26px 28px 18px 28px;background:linear-gradient(135deg,' . $accent . ' 0%,' . $subaccent . ' 100%);color:#fff;">'
            . '<div style="font-size:13px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;opacity:0.92;">' . nuevoPuertaEmailEscape($company['name']) . '</div>'
            . '<div style="font-size:26px;line-height:1.2;font-weight:800;margin-top:8px;">' . nuevoPuertaEmailEscape($heading) . '</div>'
            . ($intro !== '' ? '<div style="font-size:14px;line-height:1.6;margin-top:10px;opacity:0.95;">' . $intro . '</div>' : '')
            . '</td></tr>';

        $footerHtml = '<tr><td style="padding:0 28px 24px 28px;">'
            . '<div style="border-top:1px solid #e5e7eb;padding-top:18px;">'
            . '<div style="font-size:14px;font-weight:800;color:' . $accent . ';margin-bottom:12px;">Contact ' . nuevoPuertaEmailEscape($company['website_label']) . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:13px;line-height:1.7;color:#374151;">'
            . '<tr><td style="padding:2px 0;"><strong>Phone:</strong> ' . nuevoPuertaEmailEscape($company['phone']) . '</td></tr>'
            . '<tr><td style="padding:2px 0;"><strong>Email:</strong> <a href="mailto:' . nuevoPuertaEmailEscape($company['email']) . '" style="color:' . $accent . ';text-decoration:none;">' . nuevoPuertaEmailEscape($company['email']) . '</a></td></tr>'
            . '<tr><td style="padding:2px 0;"><strong>Office:</strong> ' . nuevoPuertaEmailEscape($company['address']) . '</td></tr>'
            . '<tr><td style="padding:2px 0;"><strong>Hours:</strong> ' . nuevoPuertaEmailEscape($company['hours']) . '</td></tr>'
            . '</table>'
            . ($footerNote !== '' ? '<div style="margin-top:14px;font-size:12px;line-height:1.6;color:#6b7280;">' . $footerNote . '</div>' : '')
            . '</div>'
            . '</td></tr>';

        return '<!DOCTYPE html>'
            . '<html><body style="margin:0;padding:0;background:#f6f8fb;font-family:Arial,sans-serif;color:#1f2937;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;background:#f6f8fb;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">'
            . $headerHtml
            . '<tr><td style="padding:24px 28px 12px 28px;">'
            . '<div style="font-size:15px;line-height:1.7;color:#1f2937;">' . $bodyHtml . '</div>'
            . '</td></tr>'
            . $footerHtml
            . '<tr><td style="padding:14px 28px 20px 28px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.6;">'
            . '&copy; ' . date('Y') . ' ' . nuevoPuertaEmailEscape($company['name']) . '. All rights reserved.'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }
}

if (!function_exists('buildNovoPuertaEmailAltBody')) {
    function buildNovoPuertaEmailAltBody(string $bodyText, string $footerNote = ''): string {
        $company = nuevoPuertaEmailCompanyInfo();
        $alt = trim($bodyText)
            . "\n\nContact {$company['name']}"
            . "\nPhone: {$company['phone']}"
            . "\nEmail: {$company['email']}"
            . "\nOffice: {$company['address']}"
            . "\nHours: {$company['hours']}";

        if ($footerNote !== '') {
            $alt .= "\n\n" . trim($footerNote);
        }

        $alt .= "\n\n© " . date('Y') . " {$company['name']}. All rights reserved.";
        return $alt;
    }
}
