# default target
default: echo

echo:
	@echo "Hello world!"

generate-proto:
	php resources/scripts/generate-proto.php

# Regenerate the temporal.api.* classes (api/) and the coresdk bridge classes
# (bridge/) from the Rust core protos, version-locked to the linked core. Needs
# the Rust core protos; set TEMPORAL_SDK_RUST_PROTOS or keep php-temporal as a
# sibling checkout.
generate-api-proto:
	php resources/scripts/generate-api-proto.php

generate-coresdk-proto:
	php resources/scripts/generate-coresdk-proto.php

generate-protos: generate-api-proto generate-coresdk-proto

generate-client:
	php resources/scripts/generate-client.php
