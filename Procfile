web: php artisan serve --host=0.0.0.0 --port=8000 --no-reload
worker: php artisan queue:work database-imports --queue=imports --timeout=660 --tries=1 --sleep=3
scheduler: php artisan schedule:work
