#!/bin/bash

php artisan config:cache

php artisan config:clear

php artisan route:clear
