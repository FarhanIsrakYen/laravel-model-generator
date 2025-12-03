<?php

namespace FarhanIsrakYen\LaravelModelMaker\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

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
        $raw = $this->argument('name');
        $path = str_replace('\\', '/', $raw);
        $className = Str::afterLast($path, '/');
        $directory = Str::contains($path, '/') ? Str::beforeLast($path, '/') : '';
        $namespace = 'App\\Models' . ($directory ? '\\' . str_replace('/', '\\', $directory) : '');

        $modelDir = app_path('Models/' . ($directory ? $directory . '/' : ''));
        if (! $this->files->isDirectory($modelDir)) {
            $this->files->makeDirectory($modelDir, 0777, true);
        }

        /* ------------------------------------------
         * FIELDS
         * ------------------------------------------ */
        $this->info("\n=== Define fields for {$className} ===");
        while (true) {
            $fname = trim($this->ask('Field name (blank = finish)'));
            if ($fname === '') break;

            $type = $this->choice('Field type', [
                'string','text','integer','bigInteger','boolean',
                'float','double','decimal','date','datetime','json','uuid','enum'
            ], 0);

            $enumValues = [];
            if ($type === 'enum') {
                $rawEnum = $this->ask('Enum values (comma separated)');
                $enumValues = array_values(array_filter(array_map('trim', explode(',', $rawEnum))));
            }

            $nullable = $this->confirm('Nullable?', false);
            $unique = $this->confirm('Unique?', false);

            $addToFillable = $this->confirm('Add to $fillable?', true);
            $addToHidden   = $this->confirm('Add to $hidden?', false);
            $addToAppends  = $this->confirm('Add to $appends?', false);

            $addCast = $this->confirm('Add to $casts?', false);
            $castType = null;
            if ($addCast) {
                $castType = $this->choice('Cast type', [
                    'int','real','float','double','string','bool','array','json','date','datetime','collection'
                ], 0);
            }

            $this->fields[] = [
                'name' => $fname,
                'type' => $type,
                'enum' => $enumValues,
                'nullable' => $nullable,
                'unique' => $unique,
                'fillable' => $addToFillable,
                'hidden' => $addToHidden,
                'append' => $addToAppends,
                'cast' => $castType,
            ];
        }

        /* ------------------------------------------
         * RELATIONSHIPS
         * ------------------------------------------ */
        $this->info("\n=== Define relationships ===");
        while ($this->confirm('Add a relationship?', false)) {
            $relName = trim($this->ask('Method name for relationship'));
            if ($relName === '') {
                $this->warn('Empty relation name — skipping.');
                continue;
            }

            $relModelRaw = $this->ask('Related model class (e.g. User or App\\Models\\User)');
            $relModel = Str::contains($relModelRaw, '\\')
                ? $relModelRaw
                : 'App\\Models\\' . str_replace('/', '\\', $relModelRaw);

            $relType = $this->choice('Relation type', [
                'hasOne','hasMany','belongsTo','belongsToMany',
                'morphOne','morphMany','morphTo','morphToMany'
            ], 0);

            $createPivot = false;
            if (in_array($relType, ['belongsToMany', 'morphToMany'])) {
                $createPivot = $this->confirm('Generate pivot table for this relation?', true);
            }

            $this->relationships[] = [
                'name' => $relName,
                'model' => $relModel,
                'type' => $relType,
                'pivot' => $createPivot,
            ];
        }

        /* ------------------------------------------
         * INDEXES — NEW FEATURE
         * ------------------------------------------ */
        $this->info("\n=== Add Indexes ===");
        while ($this->confirm('Add an index?', false)) {
            $cols = trim($this->ask('Columns for index (comma separated, e.g. otp_code, otp_expires_at)'));
            $columns = array_values(array_filter(array_map('trim', explode(',', $cols))));

            if (empty($columns)) {
                $this->warn('No valid columns — skipping.');
                continue;
            }

            $this->indexes[] = $columns;
        }

        /* ------------------------------------------
         * PREVIEW
         * ------------------------------------------ */
        $this->info("\n--- Preview: Indexes ---");
        if (empty($this->indexes)) {
            $this->line('(no indexes)');
        } else {
            $this->table(['Index Columns'], array_map(fn($idx) => [implode(', ', $idx)], $this->indexes));
        }

        if (! $this->confirm("\nGenerate model + migrations?", true)) {
            return $this->info('Cancelled.');
        }

        /* ------------------------------------------
         * MAIN MIGRATION
         * ------------------------------------------ */
        $migrationName = date('Y_m_d_His') . '_create_' . Str::snake(Str::pluralStudly($className)) . '_table.php';
        $migrationPath = database_path('migrations/' . $migrationName);

        $this->files->put(
            $migrationPath,
            $this->buildMigration($className, $this->fields, $this->relationships, $this->indexes)
        );

        $this->info("✔ Migration created: {$migrationName}");

        /* ------------------------------------------
         * PIVOT MIGRATIONS
         * ------------------------------------------ */
        foreach ($this->relationships as $r) {
            if ($r['pivot']) {
                sleep(1);
                $this->generatePivotMigration($className, $r['model'], $r['type']);
            }
        }

        /* ------------------------------------------
         * MODEL FILE
         * ------------------------------------------ */
        $modelPath = $modelDir . $className . '.php';
        $this->files->put(
            $modelPath,
            $this->buildModel($namespace, $className, $this->fields, $this->relationships)
        );

        $this->info("✔ Model created: {$modelPath}");
        $this->info("\nDone.");
    }

    /* =================================================================
     * BUILD MIGRATION
     * ================================================================= */
    protected function buildMigration(string $className, array $fields, array $relationships, array $indexes): string
    {
        $table = Str::snake(Str::pluralStudly($className));
        $lines = [];

        /* --------------------------------------
         * Fields
         * -------------------------------------- */
        foreach ($fields as $f) {
            if ($f['type'] === 'enum') {
                $vals = "['" . implode("','", $f['enum']) . "']";
                $lines[] =
                    "            \$table->enum('{$f['name']}', {$vals})"
                    . ($f['nullable'] ? '->nullable()' : '')
                    . ($f['unique'] ? '->unique()' : '')
                    . ";";
            } else {
                $lines[] =
                    "            \$table->{$f['type']}('{$f['name']}')"
                    . ($f['nullable'] ? '->nullable()' : '')
                    . ($f['unique'] ? '->unique()' : '')
                    . ";";
            }
        }

        /* --------------------------------------
         * belongsTo and morphs
         * -------------------------------------- */
        foreach ($relationships as $r) {
            if ($r['type'] === 'belongsTo') {
                $relatedTable = Str::snake(Str::pluralStudly(class_basename($r['model'])));
                $fk = Str::snake(class_basename($r['model'])) . '_id';
                $lines[] = "            \$table->foreignId('{$fk}')->constrained('{$relatedTable}')->cascadeOnDelete();";
            }

            if (in_array($r['type'], ['morphOne', 'morphMany'])) {
                $morphName = Str::snake($r['name']);
                $lines[] = "            \$table->morphs('{$morphName}');";
            }
        }

        /* --------------------------------------
         * Indexes — NEW
         * -------------------------------------- */
        foreach ($indexes as $cols) {
            $colList = "['" . implode("','", $cols) . "']";
            $lines[] = "            \$table->index({$colList});";
        }

        $schema = implode("\n", $lines);

        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
{$schema}
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    /* =================================================================
     * PIVOT GENERATOR
     * ================================================================= */
    protected function generatePivotMigration(string $ownClass, string $relatedModel, string $relationType)
    {
        $ownTable = Str::snake(Str::pluralStudly($ownClass));
        $relatedTable = Str::snake(Str::pluralStudly(class_basename($relatedModel)));

        $pair = [Str::snake(Str::singular($ownTable)), Str::snake(Str::singular($relatedTable))];
        sort($pair);
        $pivot = implode('_', $pair);

        $filename = date('Y_m_d_His') . "_create_{$pivot}_table.php";
        $path = database_path('migrations/' . $filename);

        $ownFK = Str::snake(Str::singular($ownTable)) . '_id';
        $relatedFK = Str::snake(Str::singular($relatedTable)) . '_id';

        $stub = <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$pivot}', function (Blueprint \$table) {
            \$table->id();
            \$table->foreignId('{$ownFK}')->constrained('{$ownTable}')->cascadeOnDelete();
            \$table->foreignId('{$relatedFK}')->constrained('{$relatedTable}')->cascadeOnDelete();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$pivot}');
    }
};
PHP;

        $this->files->put($path, $stub);
        $this->info("✔ Pivot migration created: {$filename}");
    }

    /* =================================================================
     * BUILD MODEL FILE
     * ================================================================= */
    protected function buildModel(string $namespace, string $className, array $fields, array $relationships): string
    {
        $fillable = [];
        $hidden = [];
        $appends = [];
        $casts = [];
        $guarded = [];

        foreach ($fields as $f) {
            if (!empty($f['fillable'])) $fillable[] = "'{$f['name']}'";
            if (!empty($f['hidden']))   $hidden[]   = "'{$f['name']}'";
            if (!empty($f['append']))   $appends[]  = "'{$f['name']}'";
            if (!empty($f['cast']))     $casts[]    = "'{$f['name']}' => '{$f['cast']}'";
            if (empty($f['fillable']))  $guarded[]  = "'{$f['name']}'";
        }

        $guardedArr = empty($fillable) ? "['*']" : "[]";

        $fillableStr = implode(', ', $fillable);
        $hiddenStr   = implode(', ', $hidden);
        $appendsStr  = implode(', ', $appends);
        $castsStr    = implode(",\n        ", $casts);

        $relationMethods = '';
        foreach ($relationships as $r) {
            $method = $r['name'];
            $model  = $r['model'];
            $type   = $r['type'];

            if (!Str::startsWith($model, ['App\\', '\\'])) {
                $modelFqn = 'App\\Models\\' . str_replace('/', '\\', $model);
            } else {
                $modelFqn = $model;
            }

            $relationMethods .= <<<PHP

    public function {$method}()
    {
        return \$this->{$type}({$modelFqn}::class);
    }

PHP;
        }

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\\Database\\Eloquent\\Model;

class {$className} extends Model
{
    protected \$fillable = [{$fillableStr}];

    protected \$guarded = {$guardedArr};

    protected \$casts = [
        {$castsStr}
    ];

    protected \$hidden = [{$hiddenStr}];

    protected \$appends = [{$appendsStr}];
{$relationMethods}
}
PHP;
    }
}
