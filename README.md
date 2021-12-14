The simple PHP regression testing framework.

## Building PHAR

phar is built using [Box](https://github.com/box-project/). Once box is installed, the phar can be built using
the following command from the project directory:

```
box build
```

Test suite for `example.com` can be run as follows:

```
$ bin/regression.php 'https://example.com'
```
