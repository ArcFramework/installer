#!/usr/bin/env bash
wget https://github.com/ArcFramework/plugin/archive/master.zip
unzip master.zip -d working
cd working/plugin-master
composer install
zip -ry ../../arc-craft.zip .
cd ../..
mv arc-craft.zip public/arc-craft.zip
rm -rf working
rm master.zip
