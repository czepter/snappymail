#!/bin/sh
# This script tests a given docker image using https://github.com/GoogleContainerTools/container-structure-test
# A locally installed container-structure-test binary is used if available (e.g. on arm64, where the docker image does not exist)
set -eu
SCRIPT_DIR=$( cd "$(dirname "$0")" && pwd )
IMAGE=${1:-}
echo "Testing image: $IMAGE"
if command -v container-structure-test > /dev/null; then
    container-structure-test test --image "$IMAGE" --config "$SCRIPT_DIR/config.yaml"
else
    docker run --rm -i \
        -v /var/run/docker.sock:/var/run/docker.sock:ro \
        -v "$SCRIPT_DIR/config.yaml:/config.yaml:ro" \
        gcr.io/gcp-runtimes/container-structure-test:latest test --image "$IMAGE" --config config.yaml
fi
