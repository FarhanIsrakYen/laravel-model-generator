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

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        // Parse name and prepare directories/namespaces
        $raw = $this->argument('name');
        $path = str_replace('\\', '/', $raw);
        $className = Str::afterLast($path, '/');
        $directory = Str::contains($path, '/') ? Str::beforeLast($path, '/') : '';
        $namespace = 'App\\Models' . ($directory ? '\\' . str_replace('/', '\\', $directory) : '');

        $modelDir = app_path('Models/' . ($directory ? $directory . '/' : ''));
        if (! $this->files->isDirectory($modelDir)) {
            $this->files->makeDirectory($modelDir, 0777, true);
        }

        // --- INTERACTIVE: Fields ---
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
                $rawEnum = $this->ask('Enum values (comma separated, e.g. draft,published)');
                $enumValues = array_values(array_filter(array_map('trim', explode(',', $rawEnum))));
            }

            $nullable = $this->confirm('Nullable?', false);
            $unique = $this->confirm('Unique?', false);

            $addToFillable = $this->confirm('Add to $fillable?', true);
            $addToHidden = $this->confirm('Add to $hidden?', false);
            $addToAppends = $this->confirm('Add to $appends?', false);

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

        // --- INTERACTIVE: Relationships ---
        $this->info("\n=== Define relationships ===");
        while ($this->confirm('Add a relationship?', false)) {
            $relName = trim($this->ask('Method name for relationship (e.g. posts, profile)'));
            if ($relName === '') {
                $this->warn('Empty relation name — skipping.');
                continue;
            }

            $relModelRaw = $this->ask('Related model class (e.g. App\\Models\\User or User)');
            // normalize model class input: if not fully-qualified, assume App\Models\<Name or directory path>
            if (Str::contains($relModelRaw, '\\')) {
                $relModel = $relModelRaw;
            } else {
                // allow nested e.g. Admin/Product
                $relModel = 'App\\Models\\' . str_replace('/', '\\', $relModelRaw);
            }

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

        // --- PREVIEW ---
        $this->info("\n--- Preview: Fields ---");
        if (empty($this->fields)) {
            $this->line('(no fields)');
        } else {
            $this->table(['Name','Type','Nullable','Unique','Fillable','Hidden','Appends','Cast'], array_map(function($f) {
                return [
                    $f['name'],
                    $f['type'] === 'enum' ? 'enum('.implode(',', $f['enum']).')' : $f['type'],
                    $f['nullable'] ? 'yes' : 'no',
                    $f['unique'] ? 'yes' : 'no',
                    $f['fillable'] ? 'yes' : 'no',
                    $f['hidden'] ? 'yes' : 'no',
                    $f['append'] ? 'yes' : 'no',
                    $f['cast'] ?? '-',
                ];
            }, $this->fields));
        }

        $this->info("\n--- Preview: Relationships ---");
        if (empty($this->relationships)) {
            $this->line('(no relationships)');
        } else {
            $this->table(['Method','Type','Related Model','Pivot?'], array_map(function($r) {
                return [$r['name'], $r['type'], $r['model'], $r['pivot'] ? 'yes' : 'no'];
            }, $this->relationships));
        }

        if (! $this->confirm("\nGenerate model, migration(s) and pivot migrations (if any)?", true)) {
            return $this->info('Cancelled.');
        }

        // --- Generate migration (main) ---
        $migrationName = date('Y_m_d_His') . '_create_' . Str::snake(Str::pluralStudly($className)) . '_table.php';
        $migrationPath = database_path('migrations/' . $migrationName);
        $this->files->put($migrationPath, $this->buildMigration($className, $this->fields, $this->relationships));
        $this->info("✔ Migration created: {$migrationName}");

        // --- Generate pivot migrations as separate files if requested ---
        foreach ($this->relationships as $r) {
            if ($r['pivot']) {
                // use small pause so timestamps differ
                sleep(1);
                $this->generatePivotMigration($className, $r['model'], $r['type']);
            }
        }

        // --- Generate Model file ---
        $modelPath = $modelDir . $className . '.php';
        $this->files->put($modelPath, $this->buildModel($namespace, $className, $this->fields, $this->relationships));
        $this->info("✔ Model created: {$modelPath}");

        $this->info("\nDone.");
    }

    /**
     * Build main migration content (string).
     */
    protected function buildMigration(string $className, array $fields, array $relationships): string
    {
        $table = Str::snake(Str::pluralStudly($className));
        $lines = [];

        // Fields
        foreach ($fields as $f) {
            if ($f['type'] === 'enum') {
                $vals = "['" . implode("','", $f['enum']) . "']";
                $lines[] = "            \$table->enum('{$f['name']}', {$vals})" . ($f['nullable'] ? '->nullable()' : '') . ($f['unique'] ? '->unique()' : '') . ";";
            } else {
                $lines[] = "            \$table->{$f['type']}('{$f['name']}')" . ($f['nullable'] ? '->nullable()' : '') . ($f['unique'] ? '->unique()' : '') . ";";
            }
        }

        // Relationships affecting this table
        foreach ($relationships as $r) {
            $type = $r['type'];
            $methodName = $r['name'];
            $relatedModel = $r['model'];

            if ($type === 'belongsTo') {
                $relatedTable = Str::snake(Str::pluralStudly(class_basename($relatedModel)));
                $fk = Str::snake(class_basename($relatedModel)) . '_id';
                $lines[] = "            \$table->foreignId('{$fk}')->constrained('{$relatedTable}')->cascadeOnDelete();";
            }

            if (in_array($type, ['morphOne', 'morphMany'])) {
                // for morphs we add morphs column naming by method name (common Symfony style)
                $morphName = Str::snake($methodName);
                $lines[] = "            \$table->morphs('{$morphName}');";
            }
        }

        $schema = implode("\n", $lines);

        $stub = <<<PHP
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

        return $stub;
    }

    /**
     * Generate a pivot migration file for belongsToMany or morphToMany.
     */
    protected function generatePivotMigration(string $ownClass, string $relatedModel, string $relationType)
    {
        $ownTable = Str::snake(Str::pluralStudly($ownClass));
        $relatedTable = Str::snake(Str::pluralStudly(class_basename($relatedModel)));

        if ($relationType === 'morphToMany') {
            // For morphToMany, use method-based pivot naming: <own>_<morphable> (developer can adjust after)
            $pivot = Str::snake(Str::singular($ownTable)) . '_' . Str::snake(Str::singular($relatedTable));
        } else {
            $pair = [Str::snake(Str::singular($ownTable)), Str::snake(Str::singular($relatedTable))];
            sort($pair);
            $pivot = implode('_', $pair);
        }

        $filename = date('Y_m_d_His') . "_create_{$pivot}_table.php";
        $path = database_path('migrations/' . $filename);

        // Build pivot migration content
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

    /**
     * Build model class content (string).
     */
    protected function buildModel(string $namespace, string $className, array $fields, array $relationships): string
    {
        $fillable = [];
        $hidden = [];
        $appends = [];
        $casts = [];
        $guarded = [];

        foreach ($fields as $f) {
            if (!empty($f['fillable'])) $fillable[] = "'{$f['name']}'";
            if (!empty($f['hidden'])) $hidden[] = "'{$f['name']}'";
            if (!empty($f['append'])) $appends[] = "'{$f['name']}'";
            if (!empty($f['cast'])) $casts[] = "'{$f['name']}' => '{$f['cast']}'";
            if (empty($f['fillable'])) $guarded[] = "'{$f['name']}'";
        }

        // If user added any fillable, set guarded to empty array; otherwise default guarded=['*']
        if (!empty($fillable)) {
            $guardedArr = '[]';
        } else {
            $guardedArr = "['*']";
        }

        $fillableStr = implode(', ', $fillable);
        $hiddenStr = implode(', ', $hidden);
        $appendsStr = implode(', ', $appends);
        $castsStr = implode(",\n        ", $casts);

        // Relationship methods
        $relationMethods = '';
        foreach ($relationships as $r) {
            $method = $r['name'];
            $model = $r['model'];
            $type = $r['type'];

            // Make sure model is fully-qualified when possible (if user passed short name, assume App\Models\...)
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

        $stub = <<<PHP
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

        return $stub;
    }
}

