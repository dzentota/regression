# To release a new version, e.g `0.1.2` run:
# make release tag=0.1.2
release:
	box build && git tag $(tag) && git push origin $(tag) && gpg -u webtota@gmail.com --detach-sign --output regression.phar.asc regression.phar
