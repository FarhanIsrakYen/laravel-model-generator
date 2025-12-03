# Laravel Model Bender

A Laravel package to interactively generate Eloquent models with fields, enums, casts, attributes, relationships, and migrations.

---

## Installation

### 1. Install via Composer

Once the package is published to Packagist, you can install it in your Laravel project:

```bash
composer require farhanisrakyen/laravel-model-bender
```

### 2. Optional: Publish vendor assets (if applicable)

```bash
php artisan vendor:publish --tag=laravel-model-bender
```

### 3. Ensure `app/Models` exists

The package supports nested directories, so make sure your `app/Models` folder exists.

---

## Usage

### Generate a Model Interactively

Run the artisan command with the model name (supports nested paths):

```bash
php artisan make:model-interactive Users/Product
```

- You will be prompted to **define fields**:
  - Name, type, nullable, unique
  - Add to `$fillable`, `$hidden`, `$appends`
  - Cast type
  - Enum values if field type is `enum`

- Then **define relationships**:
  - Method name
  - Related model (full class or relative)
  - Relationship type (`hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `morphOne`, `morphMany`, `morphTo`, `morphToMany`)
  - Optionally generate pivot migrations for many-to-many relationships

- After previewing the summary, confirm to generate:
  - Eloquent model file
  - Migration file(s)
  - Pivot migrations (if any)

### Example

```bash
php artisan make:model-interactive Blog/Post
```

- Creates `app/Models/Blog/Post.php`
- Generates `database/migrations/xxxx_xx_xx_xxxxxx_create_posts_table.php`
- Prompts interactively for fields, enums, casts, and relationships

### Notes

- Supports nested directories.
- Pivot migrations are automatically created for `belongsToMany` or `morphToMany` relationships.
- `$fillable`, `$casts`, `$hidden`, `$appends`, and `$guarded` are automatically managed.
- Factories are **not** generated.

---

## Contributing

If you find issues or want to add features, feel free to fork the repository and submit a pull request.

---

## License

MIT
