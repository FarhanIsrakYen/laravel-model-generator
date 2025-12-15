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

        $modelPath = $modelDir . $className . '.php';
        $modelExists = $this->files->exists($modelPath);

        if ($modelExists) {
            $this->info("Model {$namespace}\\{$className} already exists.");
            $choice = $this->choice('Select update option', [
                'Add fields',
                'Add relationships',
                'Add indexes',
                'Add all (fields + relationships + indexes)',
                'Cancel'
            ], 3);

            if ($choice === 'Cancel') {
                return $this->info('Cancelled.');
            }

            // Collect only selected items
            if (Str::startsWith($choice, 'Add fields') || $choice === 'Add all (fields + relationships + indexes)') {
                $this->collectFields();
            }

            if (Str::startsWith($choice, 'Add relationships') || $choice === 'Add all (fields + relationships + indexes)') {
                $this->collectRelationships();
            }

            if (Str::startsWith($choice, 'Add indexes') || $choice === 'Add all (fields + relationships + indexes)') {
                $this->collectIndexes();
            }

            // Build an alter migration for added items
            if (empty($this->fields) && empty($this->relationships) && empty($this->indexes)) {
                return $this->info('Nothing to add. Exiting.');
            }

            // Update model file (inject fillable/casts/hidden/appends and relationships)
            $this->updateModelFile($modelPath, $className, $namespace, $this->fields, $this->relationships);

            // Create alter migration
            $this->createAlterMigration($className, $this->fields, $this->relationships, $this->indexes);

            $this->info("\n✔ Update completed. Review and run `php artisan migrate`.");
            return;
        }

        // If model doesn't exist — original creation flow
        $this->collectFields();
        $this->collectRelationships();
        $this->collectIndexes();

        // Confirm
        $this->info("\n--- Preview: Indexes ---");
        if (empty($this->indexes)) {
            $this->line('(no indexes)');
        } else {
            $this->table(['Index Columns'], array_map(fn($idx) => [implode(', ', $idx)], $this->indexes));
        }

        if (! $this->confirm("\nGenerate model + migrations?", true)) {
            return $this->info('Cancelled.');
        }

        // Create main migration file and pivot migrations and model file
        $migrationName = date('Y_m_d_His') . '_create_' . Str::snake(Str::pluralStudly($className)) . '_table.php';
        $migrationPath = database_path('migrations/' . $migrationName);

        $this->files->put(
            $migrationPath,
            $this->buildMigration($className, $this->fields, $this->relationships, $this->indexes)
        );

        $this->info("✔ Migration created: {$migrationName}");

        foreach ($this->relationships as $r) {
            if ($r['pivot']) {
                sleep(1);
                $this->generatePivotMigration($className, $r['model'], $r['type']);
            }
        }

        $this->files->put(
            $modelPath,
            $this->buildModel($namespace, $className, $this->fields, $this->relationships)
        );

        $this->info("✔ Model created: {$modelPath}");
        $this->info("\nDone.");
    }

    /* -------------------------
     * Interactive collectors
     * ------------------------- */
    protected function collectFields(): void
    {
        $this->info("\n=== Define fields ===");
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
    }

    protected function collectRelationships(): void
    {
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
    }

    protected function collectIndexes(): void
    {
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
    }

    /* -------------------------
     * Update model file safely
     * ------------------------- */
    protected function updateModelFile(string $modelPath, string $className, string $namespace, array $newFields, array $newRelationships): void
    {
        $contents = $this->files->get($modelPath);

        // extract current arrays or set defaults
        $fillable = $this->extractArrayFromModel($contents, 'fillable');
        $casts = $this->extractArrayFromModel($contents, 'casts');
        $hidden = $this->extractArrayFromModel($contents, 'hidden');
        $appends = $this->extractArrayFromModel($contents, 'appends');

        // add new fields to arrays
        foreach ($newFields as $f) {
            if (!empty($f['fillable']) && !in_array($f['name'], $fillable)) $fillable[] = $f['name'];
            if (!empty($f['cast']) && !array_key_exists($f['name'], $casts)) $casts[$f['name']] = $f['cast'];
            if (!empty($f['hidden']) && !in_array($f['name'], $hidden)) $hidden[] = $f['name'];
            if (!empty($f['append']) && !in_array($f['name'], $appends)) $appends[] = $f['name'];
        }

        // replace arrays in contents
        $contents = $this->replaceArrayInModel($contents, 'fillable', $fillable);
        $contents = $this->replaceArrayInModel($contents, 'casts', $casts, true); // casts is associative
        $contents = $this->replaceArrayInModel($contents, 'hidden', $hidden);
        $contents = $this->replaceArrayInModel($contents, 'appends', $appends);

        // append relationship methods (avoid duplicates by method name)
        foreach ($newRelationships as $r) {
            $method = $r['name'];
            if (preg_match('/function\s+' . preg_quote($method) . '\s*\(/', $contents)) {
                $this->info("Method {$method} already exists in model — skipping method injection.");
                continue;
            }

            $model = $r['model'];
            $type = $r['type'];

            $modelFqn = Str::startsWith($model, ['App\\', '\\']) ? $model : 'App\\Models\\' . str_replace('/', '\\', $model);

            $relationMethod = <<<PHP


    public function {$method}()
    {
        return \$this->{$type}({$modelFqn}::class);
    }

PHP;
            // insert before final class closing brace
            $contents = preg_replace('/}\s*$/', $relationMethod . "\n}", $contents);
        }

        // write file back
        $this->files->put($modelPath, $contents);
        $this->info("✔ Model updated: {$modelPath}");
    }

    protected function extractArrayFromModel(string $contents, string $prop): array
    {
        // captures protected $prop = [ ... ];
        $pattern = '/protected\s+\$' . preg_quote($prop, '/') . '\s*=\s*\[([^\]]*)\]\s*;/m';
        if (preg_match($pattern, $contents, $m)) {
            $inner = trim($m[1]);
            if ($inner === '') return [];
            // handle associative casts
            if ($prop === 'casts') {
                $pairs = preg_split('/,(?![^\[]*\])/m', $inner);
                $result = [];
                foreach ($pairs as $p) {
                    $p = trim($p);
                    if ($p === '') continue;
                    // 'key' => 'value'
                    if (preg_match('/[\'"]([^\'"]+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $p, $mm)) {
                        $result[$mm[1]] = $mm[2];
                    }
                }
                return $result;
            }

            // normal list
            $parts = preg_split('/,(?![^\[]*\])/m', $inner);
            $values = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') continue;
                $part = trim($part, " \t\n\r\0\x0B'\"");
                if ($part !== '') $values[] = $part;
            }
            return $values;
        }

        // not found — return defaults
        return [];
    }

    /**
     * Replace or insert array property in the model contents.
     *
     * @param string $contents
     * @param string $prop
     * @param array $values If associative = true, pass ['k' => 'v'].
     * @param bool $associative
     * @return string
     */
    protected function replaceArrayInModel(string $contents, string $prop, array $values, bool $associative = false): string
    {
        if ($associative) {
            $inner = '';
            foreach ($values as $k => $v) {
                $inner .= "        '" . $k . "' => '" . $v . "',\n";
            }
            $inner = rtrim($inner, "\n");
            $replacement = "protected \$" . $prop . " = [\n" . $inner . "\n    ];";
        } else {
            $inner = '';
            foreach ($values as $v) {
                $inner .= "        '" . $v . "',\n";
            }
            $inner = rtrim($inner, "\n");
            $replacement = "protected \$" . $prop . " = [\n" . $inner . "\n    ];";
        }

        $pattern = '/protected\s+\$' . preg_quote($prop, '/') . '\s*=\s*\[[^\]]*\]\s*;/m';
        if (preg_match($pattern, $contents)) {
            return preg_replace($pattern, $replacement, $contents, 1);
        }

        // property not present — place it after class declaration (after the opening brace)
        $contents = preg_replace('/class\s+[^{]+{\s*/', "$0\n    " . $replacement . "\n\n", $contents, 1);
        return $contents;
    }

    /* -------------------------
     * Alter migration builder
     * ------------------------- */
    protected function createAlterMigration(
        string $className,
        array $newFields,
        array $newRelationships,
        array $indexes
    ): void {
        $table = Str::snake(Str::pluralStudly($className));

        $up = [];
        $down = [];

        /* --------------------------------------
        * Fields
        * -------------------------------------- */
        foreach ($newFields as $f) {
            if ($f['type'] === 'enum') {
                $vals = "['" . implode("','", $f['enum']) . "']";
                $up[] =
                    "            \$table->enum('{$f['name']}', {$vals})"
                    . ($f['nullable'] ? '->nullable()' : '')
                    . ($f['unique'] ? '->unique()' : '')
                    . ";";
            } else {
                $up[] =
                    "            \$table->{$f['type']}('{$f['name']}')"
                    . ($f['nullable'] ? '->nullable()' : '')
                    . ($f['unique'] ? '->unique()' : '')
                    . ";";
            }

            $down[] = "            if (Schema::hasColumn('{$table}', '{$f['name']}')) { \$table->dropColumn('{$f['name']}'); }";
        }

        /* --------------------------------------
        * Relationships
        * -------------------------------------- */
        foreach ($newRelationships as $r) {

            if ($r['type'] === 'belongsTo') {
                $fk = Str::snake(class_basename($r['model'])) . '_id';

                $up[] =
                    "            if (!Schema::hasColumn('{$table}', '{$fk}')) { " .
                    "\$table->foreignId('{$fk}')->constrained()->cascadeOnDelete(); }";

                $down[] =
                    "            if (Schema::hasColumn('{$table}', '{$fk}')) { " .
                    "\$table->dropForeign(['{$fk}']); \$table->dropColumn('{$fk}'); }";
            }

            if (in_array($r['type'], ['morphOne', 'morphMany'])) {
                $morph = Str::snake($r['name']);

                $up[] = "            \$table->morphs('{$morph}');";

                $down[] =
                    "            if (Schema::hasColumn('{$table}', '{$morph}_id')) { " .
                    "\$table->dropMorphs('{$morph}'); }";
            }
        }

        /* --------------------------------------
        * Indexes
        * -------------------------------------- */
        foreach ($indexes as $cols) {
            $colList = "['" . implode("','", $cols) . "']";
            $indexName = $table . '_' . implode('_', $cols) . '_index';

            $up[] = "            \$table->index({$colList}, '{$indexName}');";
            $down[] = "            \$table->dropIndex('{$indexName}');";
        }

        if (empty($up)) {
            $this->info('Nothing to migrate.');
            return;
        }

        $migrationName = date('Y_m_d_His') . '_update_' . $table . '_table.php';
        $path = database_path('migrations/' . $migrationName);

        $upBody = implode("\n", $up);
        $downBody = implode("\n", array_reverse($down)); // reverse for safety

        $stub = <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::table('{$table}', function (Blueprint \$table) {
    {$upBody}
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

        $this->files->put($path, $stub);
        $this->info("✔ Alter migration created: {$migrationName}");
    }

    /* -------------------------
     * Existing builders (create new model/migration)
     * ------------------------- */
    protected function buildMigration(string $className, array $fields, array $relationships, array $indexes): string
    {
        $table = Str::snake(Str::pluralStudly($className));
        $lines = [];

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
