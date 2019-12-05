### [1.3.3](../../compare/1.3.2...1.3.3) (2019-12-05)

- Replace `DIRECTORY_SEPARATOR` by `/` for Windows users

### [1.3.2](../../compare/1.3.1...1.3.2) (2017-07-12)

- Add _Generate proxy for Foo in Bar_ message in verbose mode, when _duplicate-original-file_ is not set or set to _true_
- Fix file name in  in verbose mode

### [1.3.1](../../compare/1.3.0...1.3.1) (2017-07-12)

- Add _Foo is overloaded by Bar_ message in verbose mode, when _duplicate-original-file_ is set to _false_

### [1.3.0](../../compare/1.2.0...1.3.0) (2017-07-12)

- Use _files_ instead of _classmap_ Composer configuration to overload classes
- Add non used files into _exclude-from-classmap_ Composer configuration to fix _Ambiguous class resolution_ warning

### [1.2.0](../../compare/1.1.3...1.2.0) (2017-05-29)

- Add ```duplicate-original-file``` configuration to indicate if you want to duplicate original classe or not

### [1.1.3](../../compare/1.1.2...1.1.3) (2016-12-28)

- Add ```static``` to ```OverloadClass::createDirectories()```

### [1.1.2](../../compare/1.1.1...1.1.2) (2016-12-28)

- Throw \Exception if ```extra/composer-overload-cache-dir``` is not defined in composer.json
- Write ```Creating dir``` when composer is called with -v
- Write ```Foo.php is overloaded by Bar.php when``` composer is called with -v

### [1.1.1 ](../../compare/1.1.0...1.1.1) (2016-11-19)

- Create cache dir if not exists

### [1.1.0](../../compare/1.0.1...1.1.0) (2016-07-19)

- Add ```extra/composer-overload-cache-dir-dev``` to set cache dir in dev
- Add ```extra/composer-overload-class-dev``` to define classes to overload in dev

### [1.0.1](../../compare/1.0.0...1.0.1) (2016-07-19)

- Fix add use to ComposerOverloadClass

### 1.0.0 (2016-07-17)

- Create ```ComposerOverloadClass```
- ```OverloadClass::overload()``` create a clone of original class, prefix it's namespace with ```ComposerOverloadClass```, and add use if needed (only for extends class name)
