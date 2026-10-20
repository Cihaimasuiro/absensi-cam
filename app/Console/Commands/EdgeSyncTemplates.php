<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\Models\FaceTemplate;
use Illuminate\Console\Command;
use PDO;

class EdgeSyncTemplates extends Command
{
    protected $signature = 'edge:sync-templates {--db=clients/edge-engine-rs/local_edge.db : Path to local SQLite db}';
    protected $description = 'Sync all enrolled face templates from Laravel database directly into Edge Engine SQLite';

    public function handle()
    {
        $dbPath = base_path($this->option('db'));

        if (! file_exists($dbPath)) {
            $this->error("Edge SQLite database not found at {$dbPath}");
            return 1;
        }

        $templates = FaceTemplate::with('student')->get();
        if ($templates->isEmpty()) {
            $this->warn('No face templates found in Laravel database. Enroll members first under Anggota menu.');
            return 0;
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare('INSERT OR REPLACE INTO templates (id, student_id, name, embedding, version, updated_at) VALUES (:id, :student_id, :name, :embedding, :version, :updated_at)');

        $count = 0;
        foreach ($templates as $t) {
            $blob = base64_decode($t->embedding_enc);
            $stmt->bindValue(':id', $t->id);
            $stmt->bindValue(':student_id', $t->student_id);
            $stmt->bindValue(':name', $t->student?->name ?? 'Anggota');
            $stmt->bindParam(':embedding', $blob, PDO::PARAM_LOB);
            $stmt->bindValue(':version', (string) $t->version_cursor);
            $stmt->bindValue(':updated_at', (string) $t->updated_at);
            $stmt->execute();
            $count++;
            $this->line("  ✓ Synced: {$t->student?->name} ({$t->student_id})");
        }

        $this->info("✅ Successfully synced {$count} face template(s) to {$this->option('db')}");
        $this->comment("Restart the engine (cargo run --bin engine-cli -- stream --device 0) to load the new templates.");

        return 0;
    }
}
