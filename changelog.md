1.0.1 (2016-07-19)
------------------

- Fix add use to ComposerOverloadClass

1.0.0 (2016-07)17)
------------------

- Create ComposerOverloadClass
- OverloadClass::overload() create a clone of original class, prefix it's namespace with ComposerOverloadClass, and add use if needed (only for extends class name)
