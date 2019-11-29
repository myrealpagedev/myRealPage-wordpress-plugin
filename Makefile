
clean:
	rm mrp-wordpress-plugin.zip
	rm mrp-blocks/dist

development:
	cd mrp-blocks && \
	npm start

compile:
	cd mrp-blocks && \
	npm run build

package:
	git archive -o mrp-wordpress-plugin.zip -9 HEAD

build: compile package
