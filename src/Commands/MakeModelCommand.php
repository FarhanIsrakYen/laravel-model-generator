<?php

namespace FarhanIsrakYen\LaravelModelMaker\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
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

        $existingFields = [];

        $modelPath = $this->getModelPath();
        if ($this->files->exists($modelPath)) {
            $contents = $this->files->get($modelPath);

            $fillable = $this->extractArrayFromModel($contents, 'fillable');
            $casts    = array_keys($this->extractArrayFromModel($contents, 'casts'));
            $hidden   = $this->extractArrayFromModel($contents, 'hidden');
            $appends  = $this->extractArrayFromModel($contents, 'appends');

            $existingFields = array_unique(array_merge($fillable, $casts, $hidden, $appends));
        }

        $addedThisSession = [];

        while (true) {
            $fname = trim($this->ask('Field name (blank = finish)'));
            if ($fname === '') break;

            if (in_array($fname, $existingFields)) {
                $this->warn("Field '{$fname}' is already defined in the existing model.");
                if (!$this->confirm('Add it again anyway? (e.g., to update casts/fillable)', false)) {
                    $this->line("Skipping '{$fname}'.");
                    continue;
                }
            }

            if (in_array($fname, $addedThisSession)) {
                $this->warn("You've already added '{$fname}' in this session.");
                if (!$this->confirm('Add it again anyway?', false)) {
                    $this->line("Skipping duplicate '{$fname}'.");
                    continue;
                }
            }

            $type = $this->choice('Field type', [
                'string', 'text', 'integer', 'bigInteger', 'boolean',
                'float', 'double', 'decimal', 'date', 'datetime', 'json', 'uuid', 'enum'
            ], 0);

            $enumValues = [];
            if ($type === 'enum') {
                $rawEnum = $this->ask('Enum values (comma separated)');
                $enumValues = array_values(array_filter(array_map('trim', explode(',', $rawEnum))));
            }

            $nullable = $this->confirm('Nullable?', false);
            $unique = $this->confirm('Unique?', false);

            $defaultFillable = !in_array($fname, $existingFields);
            $addToFillable = $this->confirm('Add to $fillable?', $defaultFillable);
            $addToHidden   = $this->confirm('Add to $hidden?', false);
            $addToAppends  = $this->confirm('Add to $appends?', false);

            $addCast = $this->confirm('Add to $casts?', !isset($casts[$fname]));
            $castType = null;
            if ($addCast) {
                $castType = $this->choice('Cast type', [
                    'int', 'real', 'float', 'double', 'string', 'bool', 'array', 'json', 'date', 'datetime', 'collection'
                ], 0);
            }

            if ($type === 'boolean' && !$nullable) {
                $this->line("<fg=yellow>Tip: Consider adding ->default(true) in migration for non-empty tables.</>");
            }

            $this->fields[] = [
                'name'      => $fname,
                'type'      => $type,
                'enum'      => $enumValues,
                'nullable'  => $nullable,
                'unique'    => $unique,
                'fillable'  => $addToFillable,
                'hidden'    => $addToHidden,
                'append'    => $addToAppends,
                'cast'      => $castType,
            ];

            $addedThisSession[] = $fname;
            if ($modelPath && $this->files->exists($modelPath)) {
                $existingFields[] = $fname;
            }
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
            if (empty($relModelRaw)) {
                $this->warn('No model provided — skipping.');
                continue;
            }

            $relModel = Str::contains($relModelRaw, '\\')
                ? $relModelRaw
                : 'App\\Models\\' . str_replace('/', '\\', $relModelRaw);

            $modelShortName = class_basename($relModel);
            $expectedPath = app_path('Models/' . str_replace(['App\\Models\\', '\\'], ['', '/'], $relModel) . '.php');

            $modelExists = $this->files->exists($expectedPath);

            if (!$modelExists) {
                $this->warn("Warning: Model '{$modelShortName}' does not exist at:");
                $this->line("    {$expectedPath}");
                $this->line("<fg=yellow>It may cause errors if the model is not created later.</>");

                if (!$this->confirm("Continue anyway? (yes if you'll create the model soon)", true)) {
                    $this->line("Skipping this relationship.");
                    continue;
                }
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

            $this->info("Relationship '{$relName}' → {$relType} {$modelShortName} added.");
        }
    }

    protected function collectIndexes(): void
    {
        $this->info("\n=== Add Indexes ===");
        $knownColumns = $this->getKnownColumns();

        while ($this->confirm('Add an index?', false)) {
            $colsInput = trim($this->ask('Columns for index (comma separated, e.g. otp_code, otp_expires_at)'));
            $columns = array_values(array_filter(array_map('trim', explode(',', $colsInput))));

            if (empty($columns)) {
                $this->warn('No valid columns provided — skipping.');
                continue;
            }

            $unknownColumns = [];
            foreach ($columns as $col) {
                if (!in_array($col, $knownColumns)) {
                    $unknownColumns[] = $col;
                }
            }

            if (!empty($unknownColumns)) {
                $this->warn("The following columns are not recognized:");
                foreach ($unknownColumns as $col) {
                    $this->line("  • {$col}");
                }
                $this->line("<fg=yellow>They might not exist in the model or database yet.</>");

                if (!$this->confirm('Create the index anyway? (useful if columns will be added later)', false)) {
                    $this->line('Skipping this index.');
                    continue;
                }
            }

            $this->indexes[] = $columns;
            $this->info("Index on [" . implode(', ', $columns) . "] added.");
        }
    }

    protected function getKnownColumns(): array
    {
        $known = [];
        foreach ($this->fields as $field) {
            $known[] = $field['name'];
        }

        $modelPath = $this->getModelPath();
        if ($this->files->exists($modelPath)) {
            $raw = $this->argument('name');
            $path = str_replace('\\', '/', $raw);
            $className = Str::afterLast($path, '/');
            $table = Str::snake(Str::pluralStudly($className));

            $migrations = $this->files->glob(database_path('migrations/*_create_' . $table . '_table.php'));
            $alterMigrations = $this->files->glob(database_path('migrations/*_update_' . $table . '_table.php'));

            $allMigrations = array_merge($migrations, $alterMigrations);
            sort($allMigrations);

            foreach ($allMigrations as $migrationPath) {
                $contents = $this->files->get($migrationPath);
                if (preg_match_all("/\\\$table->\w+\('([^']+)'/", $contents, $matches)) {
                    foreach ($matches[1] as $column) {
                        $known[] = $column;
                    }
                }
                if (preg_match_all("/\\\$table->morphs\('([^']+)'\)/", $contents, $morphMatches)) {
                    foreach ($morphMatches[1] as $morph) {
                        $known[] = $morph . '_id';
                        $known[] = $morph . '_type';
                    }
                }
                if (preg_match_all("/\\\$table->foreignId\('([^']+)'/", $contents, $fkMatches)) {
                    foreach ($fkMatches[1] as $fk) {
                        $known[] = $fk;
                    }
                }
            }

            $contents = $this->files->get($modelPath);
            $fillable = $this->extractArrayFromModel($contents, 'fillable');
            $casts    = array_keys($this->extractArrayFromModel($contents, 'casts'));
            $hidden   = $this->extractArrayFromModel($contents, 'hidden');
            $appends  = $this->extractArrayFromModel($contents, 'appends');

            $known = array_merge($known, $fillable, $casts, $hidden, $appends);
        }

        return array_unique($known);
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
        if ($prop === 'casts') {
            $pattern = '/protected\s+function\s+casts\s*\(\)\s*:\s*array\s*\{[^}]*return\s*\[\s*([^\]]*)\s*\]\s*;\s*\}/s';
            if (preg_match($pattern, $contents, $m)) {
                $inner = trim($m[1]);
                if ($inner === '') return [];

                $pairs = preg_split('/,(?![^\[]*\])/', $inner);
                $result = [];
                foreach ($pairs as $p) {
                    $p = trim($p);
                    if ($p === '') continue;
                    if (preg_match("/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $p, $mm)) {
                        $result[$mm[1]] = $mm[2];
                    }
                }
                return $result;
            }
        }

        $pattern = '/protected\s+\$' . preg_quote($prop, '/') . '\s*=\s*\[([^\]]*)\]\s*;/m';
        if (preg_match($pattern, $contents, $m)) {
            $inner = trim($m[1]);
            if ($inner === '') return [];
            if ($prop === 'casts') {
                $pairs = preg_split('/,(?![^\[]*\])/m', $inner);
                $result = [];
                foreach ($pairs as $p) {
                    $p = trim($p);
                    if ($p === '') continue;
                    if (preg_match("/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $p, $mm)) {
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
        $inner = '';
        foreach ($values as $k => $v) {
            if ($associative) {
                $inner .= "        '{$k}' => '{$v}',\n";
            } else {
                $inner .= "        '{$v}',\n";
            }
        }
        $inner = rtrim($inner, ",\n");

        if ($prop === 'casts') {
            // === CASTS: Laravel 11+ uses method, older uses property ===
            $isLaravel11 = $this->isLaravel11OrHigher();
            $hasCastsMethod = preg_match('/protected\s+function\s+casts\s*\(\)\s*:\s*array/', $contents);
            $useMethod = $isLaravel11 || $hasCastsMethod;

            if ($useMethod) {
                $replacement = empty($inner) ? '' : <<<PHP
    protected function casts(): array
    {
        return [
{$inner}
        ];
    }
PHP;
                $contents = preg_replace('/protected\s+\$casts\s*=\s*\[[^\]]*\][;\s]*/s', '', $contents);
                if ($hasCastsMethod) {
                    $pattern = '/protected\s+function\s+casts\s*\(\)\s*:\s*array\s*\{[^}]*return\s*\[[^\]]*\][^}]*\}/s';
                    if (preg_match($pattern, $contents)) {
                        $contents = preg_replace($pattern, $replacement, $contents);
                    }
                } else if (!empty($inner)) {
                    $contents = preg_replace(
                        '/(class\s+\w+\s+extends\s+\w+\s*\{)/',
                        "$1\n\n{$replacement}\n",
                        $contents,
                        1
                    );
                }
            } else {
                // Laravel ≤10: use property
                $replacement = empty($inner) ? '' : "protected \$casts = [\n{$inner}\n    ];";

                $contents = preg_replace('/protected\s+function\s+casts\s*\(\)\s*:\s*array[^}]*\}[^\n]*\n/s', '', $contents);

                $pattern = '/protected\s+\$casts\s*=\s*\[[^\]]*\][;\s]*/s';
                if (preg_match($pattern, $contents)) {
                    $contents = preg_replace($pattern, $replacement, $contents, 1);
                } else if (!empty($inner)) {
                    $contents = preg_replace(
                        '/(class\s+\w+\s+extends\s+\w+\s*\{)/',
                        "$1\n    {$replacement}\n",
                        $contents,
                        1
                    );
                }
            }
        } else {
            $replacement = empty($inner) ? '' : "protected \${$prop} = [\n{$inner}\n    ];";

            $pattern = '/protected\s+\$' . preg_quote($prop, '/') . '\s*=\s*\[[^\]]*\][;\s]*/s';
            if (preg_match($pattern, $contents)) {
                $contents = preg_replace($pattern, "\n    {$replacement}\n", $contents, 1);
            } else if (!empty($inner)) {
                $contents = preg_replace(
                    '/(class\s+\w+\s+extends\s+\w+\s*\{)/',
                    "$1\n\n    {$replacement}\n",
                    $contents,
                    1
                );
            }
        }
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
            $upLine = "            if (!Schema::hasColumn('{$table}', '{$f['name']}')) { ";

            if ($f['type'] === 'enum') {
                $vals = "['" . implode("','", $f['enum']) . "']";
                $upLine .= "\$table->enum('{$f['name']}', {$vals})";
            } else if ($f['type'] === 'decimal') {
                $upLine .= "\$table->decimal('{$f['name']}', 8, 2)";
            } else {
                $upLine .= "\$table->{$f['type']}('{$f['name']}')";
            }

            $upLine .= "; }";
            $up[] = $upLine;
            $down[] = "            if (Schema::hasColumn('{$table}', '{$f['name']}')) { \$table->dropColumn('{$f['name']}'); }";
        }

        /* --------------------------------------
        * Relationships
        * -------------------------------------- */
        foreach ($newRelationships as $r) {
            if ($r['type'] === 'belongsTo') {
                $fk = Str::snake(class_basename($r['model'])) . '_id';
                $up[] = "            if (!Schema::hasColumn('{$table}', '{$fk}')) { "
                    . "\$table->foreignId('{$fk}')->constrained()->cascadeOnDelete(); }";
                $down[] = "            if (Schema::hasColumn('{$table}', '{$fk}')) { "
                    . "\$table->dropForeign(['{$fk}']); \$table->dropColumn('{$fk}'); }";
            }

            if (in_array($r['type'], ['morphOne', 'morphMany'])) {
                $morph = Str::snake($r['name']);
                $up[] = "            if (!Schema::hasColumn('{$table}', '{$morph}_id')) { "
                    . "\$table->morphs('{$morph}'); }";
                $down[] = "            if (Schema::hasColumn('{$table}', '{$morph}_id')) { "
                    . "\$table->dropMorphs('{$morph}'); }";
            }
        }

        /* --------------------------------------
        * Indexes
        * -------------------------------------- */
        foreach ($indexes as $cols) {
            $colList = "['" . implode("','", $cols) . "']";
            $indexName = $table . '_' . implode('_', $cols) . '_index';

            $conditions = array_map(fn($col) => "Schema::hasColumn('{$table}', '{$col}')", $cols);
            $conditionCheck = implode(' && ', $conditions);

            $up[] = "            if ({$conditionCheck}) { \$table->index({$colList}, '{$indexName}'); }";

            // Down: Drop if index exists (Laravel doesn't have hasIndex, so we drop blindly — it fails silently if missing)
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
            $line = "            ";
            if ($f['type'] === 'enum') {
                $vals = "['" . implode("','", $f['enum']) . "']";
                $line .= "\$table->enum('{$f['name']}', {$vals})";
            } else if ($f['type'] === 'decimal') {
                $line .= "\$table->decimal('{$f['name']}', 8, 2)";
            } else {
                $line .= "\$table->{$f['type']}('{$f['name']}')"
                    . ($f['nullable'] ? '->nullable()' : '')
                    . ($f['unique'] ? '->unique()' : '');
            }
            $line .= ";";
            $lines[] = $line;
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
            $indexName = $table . '_' . implode('_', $cols) . '_index';
            $lines[] = "            \$table->index({$colList}, '{$indexName}');";
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
            if (!empty($f['fillable'])) {
                $fillable[] = "'{$f['name']}'";
            }
            if (!empty($f['hidden'])) {
                $hidden[] = "'{$f['name']}'";
            }
            if (!empty($f['append'])) {
                $appends[] = "'{$f['name']}'";
            }
            if (!empty($f['cast'])) {
                $casts[] = "'{$f['name']}' => '{$f['cast']}'";
            }
            if (empty($f['fillable'])) {
                $guarded[] = "'{$f['name']}'";
            }
        }

        $guardedArr = empty($fillable) ? "['*']" : "[]";
        $fillableStr = implode(', ', $fillable);
        $hiddenStr   = implode(', ', $hidden);
        $appendsStr  = implode(', ', $appends);

        $castsBlock = '';
        if (!empty($casts)) {
            $castsInner = implode("\n        ", $casts);

            if ($this->isLaravel11OrHigher()) {
                $castsBlock = <<<PHP

    protected function casts(): array
    {
        return [
        {$castsInner}
        ];
    }
PHP;
            } else {
                $castsBlock = <<<PHP

    protected \$casts = [
        {$castsInner}
    ];
PHP;
            }
        }

        // Relationships
        $relationMethods = '';
        foreach ($relationships as $r) {
            $method = $r['name'];
            $model  = $r['model'];
            $type   = $r['type'];

            $modelFqn = Str::startsWith($model, ['App\\', '\\'])
                ? $model
                : 'App\\Models\\' . str_replace('/', '\\', $model);

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

    protected \$hidden = [{$hiddenStr}];

    protected \$appends = [{$appendsStr}];{$castsBlock}{$relationMethods}
}
PHP;
    }

    protected function getModelPath(): string
    {
        $raw = $this->argument('name');
        $path = str_replace('\\', '/', $raw);
        $className = Str::afterLast($path, '/');
        $directory = Str::contains($path, '/') ? Str::beforeLast($path, '/') : '';
        $modelDir = app_path('Models/' . ($directory ? $directory . '/' : ''));
        return $modelDir . $className . '.php';
    }

    protected function isLaravel11OrHigher(): bool
    {
        $laravel = app();
        return version_compare($laravel::VERSION, '11.0', '>=');
    }
}
