.PHONY: help up down restart logs ps migrate fresh seed queue serve test lint analyse psql redis minio gitlab-up gitlab-down

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

up:          ## Start the infrastructure stack
	docker compose up -d && docker compose ps
down:        ## Stop the stack (volumes preserved)
	docker compose down
restart:     ## Restart all services
	docker compose restart
logs:        ## Tail service logs
	docker compose logs -f --tail=100
ps:          ## Show service status
	docker compose ps

migrate:     ## Run pending migrations
	php artisan migrate
fresh:       ## Drop everything, migrate, seed
	php artisan migrate:fresh --seed
seed:        ## Run seeders
	php artisan db:seed

queue:       ## Start Horizon (queue workers)
	php artisan horizon
serve:       ## Start the API on :8000
	php artisan serve --port=8000

test:        ## Run the test suite
	php artisan test
lint:        ## Fix code style
	vendor/bin/pint
analyse:     ## Static analysis
	vendor/bin/phpstan analyse --memory-limit=1G

psql:        ## Open psql inside the container
	docker exec -it pipemind-postgres psql -U pipemind -d pipemind
redis:       ## Open redis-cli inside the container
	docker exec -it pipemind-redis redis-cli
minio:       ## Open the MinIO console
	@echo "http://localhost:9001  (pipemind / pipemind123)"

tunnel:      ## Expose the API via cloudflared and re-register webhooks
	php artisan pipemind:tunnel

gitlab-up:   ## Start the local GitLab lab (heavy)
	docker compose -f docker-compose.gitlab.yml up -d
gitlab-down: ## Stop the local GitLab lab
	docker compose -f docker-compose.gitlab.yml down
