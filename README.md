monorepo-builder-path (composer plugin)
=======================================

Composer plugin intended to use in conjunction with [symplify/monorepo-builder](https://packagist.org/packages/symplify/monorepo-builder).

When having a repository of kind:

```
monorepo_dir
 ` bundles
    ` subproject1
    ` subproject2
 ` projects
    ` example1
```

where `example1` has dependencies to `monorepo/subproject1` you may like to install vendors, while keeping only a single copy of your subproject1.
Symlinks aka. [Path-Repositories](https://getcomposer.org/doc/05-repositories.md#path) to the rescue!

This also allows you to use the dependency before even publishing it to an external, without manually adding the path-repository to your composer.json

### Usage

`composer global require meeva/composer-monorepo-builder-path-plugin`

That's all. Nothing to configure. It detects your monorepo_dir by traversing up to your merged composer.json, and search all other composer.json in subdirectories (vendor excluded).

Now if you do `composer require monorepo/subproject1` within the path `monorepo_dir/projects/example1` it should use a path-symlink to install the dependency to `monorepo_dir/projects/example1/vendor`.

#### Deployment

Assuming you're gonna build a deploy version of your Project, this plugin is disabled if you're using `--no-dev`.

Also you can use the environment-variable `COMPOSER_MONOREPO` with one of these values:
 * `force` -> enable, even if `--no-dev` is present
 * `skip` -> disabled
 * _anything else_ (default) -> enabled, `unless --no-dev` is present

### Known Caveats

* Performance is bad on Windows, but I'm sure you're used to it by now
