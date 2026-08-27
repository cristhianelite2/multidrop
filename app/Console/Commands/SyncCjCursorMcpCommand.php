<?php

namespace App\Console\Commands;

use App\Domain\Suppliers\Cj\CjConnector;
use App\Models\PlatformSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncCjCursorMcpCommand extends Command
{
    protected $signature = 'cj:sync-cursor-mcp';

    protected $description = 'Escribe .cursor/mcp.json con el MCP remoto de CJ usando el access token guardado';

    public function handle(CjConnector $cj): int
    {
        $token = PlatformSetting::getValue('cj.access_token', config('cj.access_token'));
        $url = $cj->mcpServerUrl($token);

        if (! $url) {
            $this->error('No hay access token de CJ. Autoriza la API Key en General primero.');

            return self::FAILURE;
        }

        $dir = base_path('.cursor');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.'mcp.json';
        $payload = [
            'mcpServers' => [
                'cj-dropshipping' => [
                    'url' => $url,
                ],
            ],
        ];

        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->info('MCP CJ escrito en '.$path);
        $this->line('Reinicia Cursor o recarga MCP para usarlo.');

        return self::SUCCESS;
    }
}
