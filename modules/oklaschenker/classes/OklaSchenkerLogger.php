<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Journalisation des actions d'écriture du module (import tarifaire, activation
 * de suppléments, etc.) dans la table `oklaschenker_log`, avec masquage
 * systématique de tout ce qui ressemble à un secret (Access Key, mots de passe).
 *
 * Consigne absolue : « ne pas stocker les clés en clair dans les journaux ».
 */
class OklaSchenkerLogger
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    /** Clés dont la valeur ne doit jamais apparaître en clair dans un message de log. */
    private const SECRET_KEYS = ['access_key', 'accesskey', 'password', 'mot_de_passe', 'secret', 'token'];

    public static function log(string $level, string $context, string $message, array $data = []): void
    {
        if (!(bool) OklaSchenkerConfig::get(OklaSchenkerConfig::LOGGING_ENABLED)) {
            return;
        }

        $sanitizedData = self::sanitize($data);

        try {
            Db::getInstance()->insert('oklaschenker_log', [
                'level' => pSQL($level),
                'context' => pSQL($context),
                'message' => pSQL($message),
                'data' => pSQL(json_encode($sanitizedData, JSON_UNESCAPED_UNICODE)),
                'id_employee' => (int) (Context::getContext()->employee->id ?? 0),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // La journalisation ne doit jamais faire échouer l'action métier appelante.
            PrestaShopLogger::addLog('OklaSchenker: échec écriture log — ' . $e->getMessage(), 3);
        }

        if ($level === self::LEVEL_ERROR) {
            PrestaShopLogger::addLog('OklaSchenker [' . $context . '] ' . $message, 3, null, 'OklaSchenker');
        }
    }

    private static function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);
            $isSecret = false;
            foreach (self::SECRET_KEYS as $needle) {
                if (strpos($keyLower, $needle) !== false) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $out[$key] = self::mask((string) $value);
            } elseif (is_array($value)) {
                $out[$key] = self::sanitize($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $len = strlen($value);

        return str_repeat('*', max(0, $len - 4)) . substr($value, -4);
    }
}
