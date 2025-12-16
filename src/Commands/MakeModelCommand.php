<?php

namespace FarhanIsrakYen\LaravelModelMaker\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class MakeModelCommand extends Command
{
    protected $signature = 'make:model-interactive {name}';
    protected $description = 'Interactively generate a model with fields, enums, casts, attributes, relationships & migrations';

    protected Filesystem $files;

    protected array $fields = [];
    protected array $relationships = [];
    protected array $indexes = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        $raw = str_replace('\\', '/', $this->argument('name'));
        $className = Str::afterLast($raw, '/');
        $subDir = Str::contains($raw, '/') ? Str::beforeLast($raw, '/') : null;

        $namespace = 'App\\Models' . ($subDir ? '\\' . str_replace('/', '\\', $subDir) : '');
        $modelDir = app_path('Models' . ($subDir ? '/' . $subDir : ''));
        $modelPath = $modelDir . '/' . $className . '.php';

        if (! $this->files->isDirectory($modelDir)) {
            $this->files->makeDirectory($modelDir, 0755, true);
        }

        $modelExists = $this->files->exists($modelPath);

        $this->collectFields();
        $this->collectRelationships();
        $this->collectIndexes();

        if ($modelExists) {
            $this->updateModelAndMigration($className, $modelPath);
        } else {
            $this->createModelAndMigration($namespace, $className, $modelPath);
        }

        $this->info("\n✔ Done. Run `php artisan migrate`.");
    }

    protected function collectFields(): void
    {
        $this->info("\n=== Fields ===");

        while (true) {
            $name = trim($this->ask('Field name (blank to finish)'));
            if ($name === '') break;

            $type = $this->choice('Type', [
                'string','text','integer','bigInteger','boolean',
                'float','double','decimal','date','datetime','json',
                'uuid','enum'
            ]);

            $enum = [];
            if ($type === 'enum') {
                $enum = array_map('trim', explode(',', $this->ask('Enum values (comma separated)')));
            }

            $this->fields[] = [
                'name' => $name,
                'type' => $type,
                'enum' => $enum,
                'nullable' => $this->confirm('Nullable?', false),
                'unique' => $this->confirm('Unique?', false),
                'fillable' => $this->confirm('Add to $fillable?', true),
                'hidden' => $this->confirm('Add to $hidden?', false),
                'append' => $this->confirm('Add to $appends?', false),
                'cast' => $this->confirm('Add cast?', false)
                    ? $this->choice('Cast type', [
                        'int','float','double','string','bool',
                        'array','json','date','datetime','collection'
                    ])
                    : null,
            ];
        }
    }

    protected function collectRelationships(): void
    {
        $this->info("\n=== Relationships ===");

        while ($this->confirm('Add relationship?', false)) {
            $this->relationships[] = [
                'name' => $this->ask('Method name'),
                'model' => $this->ask('Related model (User or Admin/User)'),
                'type' => $this->choice('Relation type', [
                    'hasOne','hasMany','belongsTo','belongsToMany',
                    'morphOne','morphMany','morphToMany'
                ]),
                'pivot' => $this->confirm('Create pivot table?', true),
            ];
        }
    }

    protected function collectIndexes(): void
    {
        $this->info("\n=== Indexes ===");

        while ($this->confirm('Add index?', false)) {
            $cols = array_map('trim', explode(',', $this->ask(
                'Columns (comma separated)'
            )));
            if ($cols) $this->indexes[] = $cols;
        }
    }

    /* ==========Create======= */
    protected function createModelAndMigration(string $namespace, string $class, string $path): void
    {
        $this->files->put($path, $this->buildModel($namespace, $class));
        $this->files->put(
            database_path('migrations/' . now()->format('Y_m_d_His') . "_create_" . Str::snake(Str::pluralStudly($class)) . "_table.php"),
            $this->buildCreateMigration($class)
        );
    }

    /* ==========Update======= */
    protected function updateModelAndMigration(string $class, string $path): void
    {
        $this->files->put($path, $this->injectIntoModel(
            $this->files->get($path)
        ));

        $this->files->put(
            database_path('migrations/' . now()->format('Y_m_d_His') . "_update_" . Str::snake(Str::pluralStudly($class)) . "_table.php"),
            $this->buildAlterMigration($class)
        );
    }

    /* ==========================================================
     | Model Builder
     ========================================================== */
    protected function buildModel(string $namespace, string $class): string
    {
        $fillable = [];
        $hidden = [];
        $appends = [];
        $casts = [];

        foreach ($this->fields as $f) {
            if ($f['fillable']) $fillable[] = "'{$f['name']}'";
            if ($f['hidden'])   $hidden[] = "'{$f['name']}'";
            if ($f['append'])   $appends[] = "'{$f['name']}'";
            if ($f['cast'])     $casts[] = "'{$f['name']}' => '{$f['cast']}'";
        }

        $castsBlock = $this->useCastsMethod()
            ? $this->castsMethodBlock($casts)
            : $this->castsPropertyBlock($casts);

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Model;

class {$class} extends Model
{
    protected \$fillable = [{$this->implode($fillable)}];

{$castsBlock}

    protected \$hidden = [{$this->implode($hidden)}];

    protected \$appends = [{$this->implode($appends)}];
}
PHP;
    }

    /* ==========================================================
     | Migration Builders
     ========================================================== */
    protected function buildCreateMigration(string $class): string
    {
        $table = Str::snake(Str::pluralStudly($class));

        return $this->migrationStub($table, true);
    }

    protected function buildAlterMigration(string $class): string
    {
        $table = Str::snake(Str::pluralStudly($class));

        return $this->migrationStub($table, false);
    }

    protected function migrationStub(string $table, bool $create): string
    {
        $up = [];
        $down = [];

        foreach ($this->fields as $f) {
            $line = $f['type'] === 'enum'
                ? "\$table->enum('{$f['name']}', " . var_export($f['enum'], true) . ")"
                : "\$table->{$f['type']}('{$f['name']}')";

            if ($f['nullable']) $line .= '->nullable()';
            if ($f['unique'])   $line .= '->unique()';

            $up[] = $line . ';';
            $down[] = "\$table->dropColumn('{$f['name']}');";
        }

        foreach ($this->indexes as $i) {
            $up[] = "\$table->index(" . var_export($i, true) . ");";
            $down[] = "\$table->dropIndex(" . Str::snake(implode('_', $i)) . "_index);";
        }

        $upBody = implode("\n            ", $up);
        $downBody = implode("\n            ", $down);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::{$create ? 'create' : 'table'}('{$table}', function (Blueprint \$table) {
            {$create ? '$table->id();' : ''}
            {$upBody}
            {$create ? '$table->timestamps();' : ''}
        });
    }

    public function down(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            {$downBody}
        });
    }
};
PHP;
    }

    /* ==========================================================
     | Helpers
     ========================================================== */
    protected function useCastsMethod(): bool
    {
        return version_compare(app()->version(), '11.0.0', '>=');
    }

    protected function castsMethodBlock(array $casts): string
    {
        $inner = implode(",\n            ", $casts);

        return <<<PHP
    protected function casts(): array
    {
        return [
            {$inner}
        ];
    }
PHP;
    }

    protected function castsPropertyBlock(array $casts): string
    {
        $inner = implode(",\n        ", $casts);

        return <<<PHP
    protected \$casts = [
        {$inner}
    ];
PHP;
    }

    protected function implode(array $items): string
    {
        return implode(', ', $items);
    }

    protected function injectIntoModel(string $contents): string
    {
        // Simple append strategy (safe & predictable)
        return preg_replace('/}\s*$/', "\n// Updated by laravel-model-bender\n}", $contents);
    }
}
