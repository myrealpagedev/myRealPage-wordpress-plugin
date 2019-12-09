default: build

clean:
	rm -f mrp-wordpress-plugin.zip
	rm -Rf mrp-blocks/dist

development:
	cd mrp-blocks && \
	npm start

compile:
	cd mrp-blocks && \
	npm run build

build: clean compile

package:
	git archive -o mrp-wordpress-plugin.zip -9 HEAD


