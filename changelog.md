1.1.1 (2016-11-19)
------------------

- Create cache dir if not exists

1.1.0 (2016-07-19)
------------------

- Add extra/composer-overload-cache-dir-dev to set cache dir in dev
- Add extra/composer-overload-class-dev to define classes to overload in dev

1.0.1 (2016-07-19)
------------------

- Fix add use to ComposerOverloadClass

1.0.0 (2016-07)17)
------------------

- Create ComposerOverloadClass
- OverloadClass::overload() create a clone of original class, prefix it's namespace with ComposerOverloadClass, and add use if needed (only for extends class name)
