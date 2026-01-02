#!/bin/bash

echo "🔄 Starting ngrok tunnels for MyEventLane..."

# Define paths
NGROK_YML="$HOME/.ngrok2/ngrok.yml"
DOCKER_IMAGE="ngrok/ngrok:alpine"

# Confirm config file exists
if [ ! -f "$CONFIG_PATH" ]; then
  echo "❌ Config file not found at $CONFIG_PATH"
  exit 1
fi

# Run ngrok inside Docker with mounted config
docker run -it --rm \
  --network ddev_default \
  -v "$NGROK_YML":/ngrok.yml \
  $DOCKER_IMAGE \
  start --all --config /ngrok.yml &

# Give it 3 seconds to start (optional tweak)
sleep 3

# If that exits, ngrok has stopped or failed
echo ""

echo "⛔ ngrok process has exited."
# Output your tunnel URLs
echo ""
echo "✅ Tunnels launched!"
echo "🔐 SSH Access:"
echo "   ssh anna@1.tcp.au.ngrok.io -p 20819"
echo ""
echo "🌐 Dev Site:"
echo "   https://myeventlane.au.ngrok.io"
echo ""
echo "📨 MailHog:"
echo "   https://mailhog-myeventlane.au.ngrok.io"
echo ""

# macOS clipboard copy (SSH command)
echo "ssh anna@1.tcp.au.ngrok.io -p 20819" | pbcopy
echo "📋 SSH command copied to clipboard!"

echo ""
echo "💡 Leave this terminal open to keep tunnels alive."

