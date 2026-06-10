# default target
default: echo

echo:
	@echo "Hello world!"

generate-proto:
	php resources/scripts/generate-proto.php

# Regenerate the coresdk bridge classes (activity worker) into bridge/.
# Needs the Rust core protos; set TEMPORAL_SDK_RUST_PROTOS or keep php-temporal
# as a sibling checkout. See resources/scripts/generate-coresdk-proto.php.
generate-coresdk-proto:
	php resources/scripts/generate-coresdk-proto.php

generate-client:
	php resources/scripts/generate-client.php
