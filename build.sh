#!/bin/bash
# Usage: ./build.sh REGISTRY=<registry> [IMAGE_TAG=latest] [PLATFORMS=linux/amd64,linux/arm64] [PUSH=true]
# Example: ./build.sh REGISTRY=docker.io/myuser/lastfm IMAGE_TAG=v1.0

set -e

REGISTRY="${REGISTRY:-}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
PUSH="${PUSH:-true}"
BUILDER_NAME="lastfm-builder"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

if [ -z "${REGISTRY}" ]; then
    echo -e "${RED}ERROR: REGISTRY is required${NC}"
    echo ""
    echo "Usage: ./build.sh REGISTRY=<registry> [IMAGE_TAG=latest] [PLATFORMS=linux/amd64,linux/arm64] [PUSH=true]"
    echo ""
    echo "Example:"
    echo "  REGISTRY=docker.io/myuser/lastfm ./build.sh"
    echo "  REGISTRY=myregistry.com/lastfm IMAGE_TAG=v1.0 ./build.sh"
    exit 1
fi

IMAGE_NAME="${REGISTRY}"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN} Lastfm Docker Build${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "Registry: ${YELLOW}${REGISTRY}${NC}"
echo -e "Image: ${YELLOW}${IMAGE_NAME}:${IMAGE_TAG}${NC}"
echo -e "Platforms: ${YELLOW}${PLATFORMS}${NC}"
echo -e "Push: ${YELLOW}${PUSH}${NC}"
echo ""

if ! command -v docker &> /dev/null; then
    echo -e "${RED}ERROR: Docker is not installed or not in PATH${NC}"
    exit 1
fi

if ! docker info &> /dev/null; then
    echo -e "${RED}ERROR: Docker daemon is not running. Please start Docker.${NC}"
    exit 1
fi

# Check if buildx builder exists, create if not
if ! docker buildx inspect "${BUILDER_NAME}" &> /dev/null; then
    echo -e "${GREEN}Creating buildx builder '${BUILDER_NAME}'...${NC}"
    docker buildx create --name "${BUILDER_NAME}" --driver docker-container --bootstrap
fi

# Use the builder
docker buildx use "${BUILDER_NAME}"

echo -e "${GREEN}Building Docker image for platforms: ${PLATFORMS}...${NC}"
echo ""

if [ "${PUSH}" = "true" ]; then
    docker buildx build \
        --platform "${PLATFORMS}" \
        -t "${IMAGE_NAME}:${IMAGE_TAG}" \
        -f Dockerfile \
        --push \
        .
    
    echo ""
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN} Build & Push Successful!${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    echo -e "Image: ${YELLOW}${IMAGE_NAME}:${IMAGE_TAG}${NC}"
    echo -e "Platforms: ${YELLOW}${PLATFORMS}${NC}"
else
    docker buildx build \
        --platform "${PLATFORMS}" \
        -t "${IMAGE_NAME}:${IMAGE_TAG}" \
        -f Dockerfile \
        --load \
        .
    
    echo ""
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN} Build Successful!${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    echo -e "Image: ${YELLOW}${IMAGE_NAME}:${IMAGE_TAG}${NC}"
    echo -e "Platforms: ${YELLOW}${PLATFORMS}${NC}"
    echo ""
    echo -e "${YELLOW}Note: Multi-platform images with --load only load the current platform's image.${NC}"
    echo -e "${YELLOW}Use PUSH=true to push all platforms to the registry.${NC}"
fi
