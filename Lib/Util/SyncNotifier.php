<?php
/**
 * This file is part of ICalSync plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\ICalSync\Lib\Util;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ICalSync\Model\ICalSyncLog;

/**
 * Email notification utility for sync errors and critical events.
 *
 * Sends email notifications to the configured admin email address
 * when sync operations encounter errors or critical issues.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class SyncNotifier
{
    /** @var string Minimal severity at which notifications are sent */
    public const string SEVERITY_INFO = 'info';
    public const string SEVERITY_WARNING = 'warning';
    public const string SEVERITY_ERROR = 'error';
    public const string SEVERITY_CRITICAL = 'critical';

    /**
     * Send a notification to the configured admin email.
     *
     * @param string $message The notification message
     * @param string $severity One of SEVERITY_* constants
     * @param array $context Additional context data (optional)
     * @return bool True if notification was sent (or at least attempted)
     */
    public static function notifyAdmin(string $message, string $severity = self::SEVERITY_ERROR, array $context = []): bool
    {
        // Check if notifications are enabled
        $enabled = Tools::settings('icalsync', 'email_notifications_enabled', false);
        if (!$enabled) {
            return false;
        }

        // Check minimum severity level
        $minSeverity = Tools::settings('icalsync', 'email_notification_level', self::SEVERITY_ERROR);
        if (!self::isSeverityMet($severity, $minSeverity)) {
            return false;
        }

        // Get admin email
        $adminEmail = Tools::settings('icalsync', 'admin_email', '');
        if (empty($adminEmail)) {
            // Fall back to FS configured email if available
            $adminEmail = self::getFallbackEmail();
        }

        if (empty($adminEmail)) {
            // No email configured — log and return
            Tools::log()->warning('sync-notify-no-email');
            return false;
        }

        // Build subject and body
        $subject = '[ICalSync] ' . ucfirst($severity) . ': Sync Notification';
        $body = self::buildEmailBody($message, $severity, $context);

        // Try to send via FacturaScripts mail system
        try {
            $sent = self::sendMail($adminEmail, $subject, $body);
            if ($sent) {
                ICalSyncLog::logSuccess('email-sent', 'Notification sent to ' . $adminEmail
                    . ' [' . $severity . ']: ' . $message);
            } else {
                ICalSyncLog::logError('email-send-error', 'Failed to send email to ' . $adminEmail);
            }
            return $sent;
        } catch (\Exception $e) {
            Tools::log()->error('email-send-error', ['%error%' => $e->getMessage()]);
            ICalSyncLog::logError('email-send-error', $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the given severity meets the minimum threshold.
     *
     * @param string $severity
     * @param string $minSeverity
     * @return bool
     */
    private static function isSeverityMet(string $severity, string $minSeverity): bool
    {
        $levels = [
            self::SEVERITY_INFO => 0,
            self::SEVERITY_WARNING => 1,
            self::SEVERITY_ERROR => 2,
            self::SEVERITY_CRITICAL => 3,
        ];

        $actualLevel = $levels[$severity] ?? 0;
        $minLevel = $levels[$minSeverity] ?? 2;

        return $actualLevel >= $minLevel;
    }

    /**
     * Build an HTML email body.
     *
     * @param string $message
     * @param string $severity
     * @param array $context
     * @return string
     */
    private static function buildEmailBody(string $message, string $severity, array $context): string
    {
        $color = match ($severity) {
            self::SEVERITY_CRITICAL => '#dc3545',
            self::SEVERITY_ERROR => '#ffc107',
            self::SEVERITY_WARNING => '#0d6efd',
            default => '#198754',
        };

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>ICalSync Notification</title></head><body>';
        $html .= '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2 style="color: ' . $color . ';">ICalSync ' . ucfirst($severity) . '</h2>';
        $html .= '<p>' . htmlspecialchars($message) . '</p>';

        if (!empty($context)) {
            $html .= '<hr><h4>Context</h4><table style="border-collapse: collapse; width: 100%;">';
            foreach ($context as $key => $value) {
                $html .= '<tr><td style="padding: 4px 8px; border: 1px solid #ddd; font-weight: bold;">'
                    . htmlspecialchars((string)$key) . '</td>'
                    . '<td style="padding: 4px 8px; border: 1px solid #ddd;">'
                    . htmlspecialchars((string)$value) . '</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '<hr><p style="color: #666; font-size: 12px;">';
        $html .= 'Sent by ICalSync plugin for FacturaScripts at ' . date('Y-m-d H:i:s') . '</p>';
        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Send an email using FacturaScripts' built-in mailer or fallback.
     *
     * @param string $to
     * @param string $subject
     * @param string $htmlBody
     * @return bool
     */
    private static function sendMail(string $to, string $subject, string $htmlBody): bool
    {
        // Try using FacturaScripts' EmailNotification model first
        if (class_exists('FacturaScripts\\Core\\Base\\EmailModel')) {
            try {
                $emailModel = new \FacturaScripts\Core\Base\EmailModel();
                $emailModel->addAddress($to);
                $emailModel->subject = $subject;
                $emailModel->text = $htmlBody;
                return $emailModel->send();
            } catch (\Exception $e) {
                // Fall through to fallback
                Tools::log()->warning('email-model-failed', ['%error%' => $e->getMessage()]);
            }
        }

        // Try using FS's Mailer
        if (class_exists('FacturaScripts\\Core\\Base\\Mailer')) {
            try {
                $mailer = new \FacturaScripts\Core\Base\Mailer();
                $mailer->Subject = $subject;
                $mailer->Body = $htmlBody;
                $mailer->AltBody = strip_tags($htmlBody);
                $mailer->addAddress($to);
                return $mailer->send();
            } catch (\Exception $e) {
                Tools::log()->warning('mailer-failed', ['%error%' => $e->getMessage()]);
            }
        }

        // Fallback: use PHP's mail() function
        $headers = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'From: icalsync@' . (gethostname() ?: 'localhost') . "\r\n";

        return mail($to, $subject, $htmlBody, $headers);
    }

    /**
     * Get fallback admin email from FS settings.
     *
     * @return string
     */
    private static function getFallbackEmail(): string
    {
        // Try various possible FS settings
        $candidates = [
            Tools::settings('icalsync', 'admin_email', ''),
            Tools::settings('fs', 'email', ''),
            Tools::settings('fs', 'admin_email', ''),
        ];

        foreach ($candidates as $email) {
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }
}
