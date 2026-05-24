# ===================================================
# PHP/Laravel Package Development Makefile
# ===================================================
# This Makefile provides utilities for package development,
# including code quality checks, version management, and file tracking.
# ===================================================

# ---------------------------------------------------
# Tool Executables
# ---------------------------------------------------
PINT = ./vendor/bin/pint
PHPSTAN = ./vendor/bin/phpstan
RECTOR = ./vendor/bin/rector
PSALM = ./vendor/bin/psalm

# ---------------------------------------------------
# Source Configuration
# ---------------------------------------------------
SOURCE_DIRS = src config database tests
IGNORED_FILES = CHANGED_FILES.md FILES_CHECKLIST.md psalm.md phpstan.md pint-test.md Makefile pint.md .gitkeep

# ---------------------------------------------------
# Version Control Operations
# ---------------------------------------------------

.PHONY: pre-commit
pre-commit: ## Run pre-commit checks (format, test)
	@echo "🔍 Running pre-commit checks..."
	@rm -f all.txt diff.txt
	@make lint-all-fix-md
	@make test
	@echo "✅ Pre-commit checks passed"

.PHONY: toggle-prompts
toggle-prompts: ## Toggle prompts in .gitignore
	@if grep -q '^prompts/' .gitignore; then \
		sed -i.bak 's/^prompts\//#prompts\//' .gitignore; \
		echo "✅ prompts/ commented in .gitignore"; \
	else \
		sed -i.bak 's/^#\s*prompts\//prompts\//' .gitignore; \
		echo "✅ prompts/ uncommented in .gitignore"; \
	fi

.PHONY: git-commit-push
git-commit-push: pre-commit ## Commit and push all changes with confirmation
	@make toggle-prompts
	@read -p "Enter commit message: " commit_message; \
	if [ -z "$$commit_message" ]; then \
		echo "❌ Error: Commit message cannot be empty"; \
		exit 1; \
	fi; \
	git add .; \
	git commit -m "$$commit_message"; \
	git push
	@make toggle-prompts

.PHONY: git-tag
git-tag: ## Create and push a new version tag (major/minor/patch)
	@bash -c '\
	read -p "Tag type (major/minor/patch): " tag_type; \
	last_tag=$$(git tag --sort=-v:refname | head -n 1); \
	if [ -z "$$last_tag" ]; then last_tag="0.0.0"; fi; \
	major=$$(echo $$last_tag | cut -d. -f1); \
	minor=$$(echo $$last_tag | cut -d. -f2); \
	patch=$$(echo $$last_tag | cut -d. -f3); \
	case "$$tag_type" in \
		major) major=$$((major + 1)); minor=0; patch=0;; \
		minor) minor=$$((minor + 1)); patch=0;; \
		patch) patch=$$((patch + 1));; \
		*) echo "❌ Invalid tag type: $$tag_type"; exit 1;; \
	esac; \
	new_tag="$$major.$$minor.$$patch"; \
	git tag -a "$$new_tag" -m "Release $$new_tag"; \
	git push origin "$$new_tag"; \
	echo "✅ Released new tag: $$new_tag"; \
	'

.PHONY: git-tag-republish
git-tag-republish: ## Force push the last tag
	@bash -c '\
	last_tag=$$(git tag --sort=-v:refname | head -n 1); \
	if [ -z "$$last_tag" ]; then echo "❌ No tags found!"; exit 1; fi; \
	echo "Republishing last tag: $$last_tag"; \
	git push origin "$$last_tag" --force; \
	echo "✅ Tag $$last_tag republished"; \
	'

# ---------------------------------------------------
# 📝 WORK SUMMARY & AI DIFF
# ---------------------------------------------------

.PHONY: work-create-summary
work-create-summary: ## Create a work summary markdown file
	@read -p "📝 Nom du résumé : " NAME; \
	DATE=$$(date +%Y-%m-%d); \
	TIME=$$(date +%H-%M-%S); \
	FILENAME="docs/work-summaries/$${DATE}T$${TIME}-$${NAME}.md"; \
	mkdir -p docs/work-summaries; \
	echo "📋 Colle le contenu Markdown (CTRL+D) :"; \
	cat > "$$FILENAME"; \
	echo "✅ Fichier créé : $$FILENAME"

.PHONY: generate-ai-diff
generate-ai-diff: ## Generate clean diff for AI review
	@read -p "📁 Chemins (vide = tous) : " DIR_PATHS; \
	DATE=$$(date +%Y-%m-%d); \
	TIME=$$(date +%H-%M-%S); \
	DIFF_FILENAME="docs/diffs/$${DATE}T$${TIME}-diff.md"; \
	mkdir -p docs/diffs; \
	echo "Tu es un expert en revue de code et en conventions de commits (Conventional Commits)." > $$DIFF_FILENAME; \
	echo "" >> $$DIFF_FILENAME; \
	echo "À partir du diff Git ci-dessous, fais les choses suivantes :" >> $$DIFF_FILENAME; \
	echo "" >> $$DIFF_FILENAME; \
	echo "1. Propose un nom de fichier pour le work summary" >> $$DIFF_FILENAME; \
	echo "2. Propose un nom de commit clair et concis en anglais avec le format <type>(<scope>): <description>" >> $$DIFF_FILENAME; \
	echo "3. Rédige un résumé du travail effectué en quelques phrases (en français)" >> $$DIFF_FILENAME; \
	echo "4. Donne une liste d'exemples concrets de changements" >> $$DIFF_FILENAME; \
	echo "" >> $$DIFF_FILENAME; \
	echo "Voici le diff :" >> $$DIFF_FILENAME; \
	echo "" >> $$DIFF_FILENAME; \
	echo '```diff' >> $$DIFF_FILENAME; \
	if [ -z "$$DIR_PATHS" ]; then \
		git diff HEAD -- . ':!*.phpunit.result.cache' ':!diff.txt' ':!docs/*' >> $$DIFF_FILENAME; \
	else \
		git diff HEAD -- $$DIR_PATHS ':!*.phpunit.result.cache' ':!diff.txt' ':!docs/*' >> $$DIFF_FILENAME; \
	fi; \
	echo '```' >> $$DIFF_FILENAME; \
	echo "" >> $$DIFF_FILENAME; \
	echo "✅ Diff généré : $$DIFF_FILENAME"

.PHONY: work-create-summary-from-diff
work-create-summary-from-diff: generate-ai-diff ## Create work summary from AI diff analysis
	@echo ""
	@echo "📄 Le fichier diff a été généré dans docs/diffs/"
	@echo "📋 Envoie ce fichier à l'IA pour analyse"
	@echo ""
	@read -p "Appuie sur ENTRÉE quand tu as reçu la réponse de l'IA..." ENTER
	@echo ""
	@read -p "📝 Nom du fichier work summary : " NAME; \
	DATE=$$(date +%Y-%m-%d); \
	TIME=$$(date +%H-%M-%S); \
	FILENAME="docs/work-summaries/$${DATE}T$${TIME}-$${NAME}.md"; \
	mkdir -p docs/work-summaries; \
	echo "📋 Colle la réponse de l'IA (CTRL+D pour terminer) :"; \
	cat > "$$FILENAME"; \
	echo "✅ Work summary créé : $$FILENAME"; \
	echo ""; \
	read -p "📝 Message de commit (proposé par l'IA) : " COMMIT_MSG; \
	if [ -n "$$COMMIT_MSG" ]; then \
		git add .; \
		git commit -m "$$COMMIT_MSG"; \
		echo "✅ Commit créé"; \
		read -p "🚀 Pousser le commit ? (o/N) : " PUSH_CONFIRM; \
		if [ "$$PUSH_CONFIRM" = "o" ] || [ "$$PUSH_CONFIRM" = "O" ]; then \
			git push origin $(GIT_BRANCH); \
			echo "✅ Commit poussé"; \
		fi \
	fi

# ---------------------------------------------------
# File Management Operations
# ---------------------------------------------------

.PHONY: update-checklist
update-checklist: ## Update file checklist
	@echo "📋 Updating FILES_CHECKLIST.md..."
	@if [ -f FILES_CHECKLIST.md ]; then \
		grep -E '^[0-9]+\. .* \[[ xX]\]$$' FILES_CHECKLIST.md > .existing_checklist.tmp; \
		awk -F' ' '{ \
			file_path=""; \
			for(i=2;i<NF;i++) { \
				if(i>2) file_path=file_path" "; \
				file_path=file_path$$i; \
			} \
			checkmark_state=$$NF; \
			print file_path " " checkmark_state \
		}' .existing_checklist.tmp > .existing_files.tmp; \
	else \
		touch .existing_files.tmp; \
		touch FILES_CHECKLIST.md; \
	fi; \
	echo "# Project File Checklist" > FILES_CHECKLIST.md; \
	echo "*Last updated: $$(date)*" >> FILES_CHECKLIST.md; \
	echo "" >> FILES_CHECKLIST.md; \
	echo "## Previously Checked Files" >> FILES_CHECKLIST.md; \
	file_count=1; \
	grep '\[x\]' .existing_files.tmp | sort | uniq | while read -r line; do \
		file_path=$$(echo "$$line" | awk '{$$NF=""; print $$0}' | sed 's/ $$//'); \
		echo "$$file_count. $$file_path [x]" >> FILES_CHECKLIST.md; \
		file_count=$$((file_count + 1)); \
	done; \
	previously_checked_files=$$(grep '\[x\]' .existing_files.tmp | awk '{$$NF=""; print $$0}' | sed 's/ $$//'); \
	echo "" >> FILES_CHECKLIST.md; \
	echo "## Other Files" >> FILES_CHECKLIST.md; \
	file_count=1; \
	find $(SOURCE_DIRS) -type f | sort | while read -r file_path; do \
		if ! echo "$$previously_checked_files" | grep -Fxq "$$file_path" 2>/dev/null; then \
			echo "$$file_count. $$file_path [ ]" >> FILES_CHECKLIST.md; \
			file_count=$$((file_count + 1)); \
		fi; \
	done; \
	rm -f .existing_checklist.tmp .existing_files.tmp; \
	echo "✅ FILES_CHECKLIST.md updated successfully"

.PHONY: list-modified-files
list-modified-files: ## List modified files
	@echo "📝 Updating CHANGED_FILES.md..."
	@previously_checked_files=$$(grep -E '^[0-9]+\. .* \[[xX]\]' FILES_CHECKLIST.md | sed 's/^[0-9]\+\. //' | sed 's/ *\[[xX]\]$$//'); \
	modified_file_count=0; \
	all_files=$$( (git diff --name-only; git ls-files --others --exclude-standard) | sort -u ); \
	echo "# Changed and Untracked Files" > CHANGED_FILES.md; \
	echo "*Updated: $$(date)*" >> CHANGED_FILES.md; \
	echo "" >> CHANGED_FILES.md; \
	echo "## Files to Review (modifications on checked files)" >> CHANGED_FILES.md; \
	for file_path in $$all_files; do \
		if echo "$$previously_checked_files" | grep -Fxq "$$file_path"; then \
			modified_file_count=$$((modified_file_count + 1)); \
			echo "$$modified_file_count. $$file_path [x]" >> CHANGED_FILES.md; \
		fi; \
	done; \
	if [ $$modified_file_count -eq 0 ]; then \
		echo "*(No modified files in this category)*" >> CHANGED_FILES.md; \
	fi; \
	echo "" >> CHANGED_FILES.md; \
	echo "## Other Modified Files" >> CHANGED_FILES.md; \
	modified_file_count=0; \
	for file_path in $$all_files; do \
		should_skip_file=0; \
		for ignored_file in $$(echo -e "$(IGNORED_FILES)"); do \
			if [ "$$file_path" = "$$ignored_file" ]; then should_skip_file=1; break; fi; \
		done; \
		if [ $$should_skip_file -eq 0 ] && ! echo "$$previously_checked_files" | grep -Fxq "$$file_path"; then \
			modified_file_count=$$((modified_file_count + 1)); \
			echo "$$modified_file_count. $$file_path [ ]" >> CHANGED_FILES.md; \
		fi; \
	done; \
	if [ $$modified_file_count -eq 0 ]; then \
		echo "*(No modified files in this category)*" >> CHANGED_FILES.md; \
	fi; \
	echo "✅ CHANGED_FILES.md updated successfully"

.PHONY: update-all
update-all: update-checklist list-modified-files ## Update all file management files
	@echo "✅ All file management updates completed"

.PHONY: concat-all
concat-all: ## Concatenate all PHP files into all.txt
	@read -p "📁 Enter the source directory path to scan (leave empty for default './src ./tests'): " SOURCE_PATH; \
	if [ -z "$$SOURCE_PATH" ]; then \
		SOURCE_DIRS="./src ./tests"; \
		echo "🔗 Concatenating all PHP files from default directories: $${SOURCE_DIRS} into all.txt..."; \
	else \
		SOURCE_DIRS="$$SOURCE_PATH"; \
		echo "🔗 Concatenating all PHP files from directory: $${SOURCE_DIRS} into all.txt..."; \
	fi; \
	find $${SOURCE_DIRS} -type f -name "*.php" -exec sh -c 'echo ""; echo "// ==== {} ==="; echo ""; cat {}' \; > all.txt; \
	echo "✅ File all.txt generated successfully from: $${SOURCE_DIRS}"

# ---------------------------------------------------
# Testing
# ---------------------------------------------------

.PHONY: clean-testbench-migrations
clean-testbench-migrations: ## Clean Orchestra Testbench migrations
	@echo "🧹 Cleaning Orchestra Testbench migrations..."
	@rm -f vendor/orchestra/testbench-core/laravel/database/migrations/*_create_nemesis_*_table.php 2>/dev/null || true
	@rm -f vendor/orchestra/testbench-core/laravel/database/migrations/*_create_test_*_table.php 2>/dev/null || true
	@echo "✅ Testbench migrations cleaned"

.PHONY: clean-testbench-cache
clean-testbench-cache: ## Clean Testbench cache
	@echo "🧹 Cleaning Testbench cache..."
	@rm -rf bootstrap/cache/*.php 2>/dev/null || true
	@rm -rf storage/framework/cache/* 2>/dev/null || true
	@echo "✅ Testbench cache cleaned"

.PHONY: clean-testbench-all
clean-testbench-all: clean-testbench-migrations clean-testbench-cache ## Clean all Testbench data
	@echo "🧹 Cleaning all Testbench data..."
	@rm -rf vendor/orchestra/testbench-core/laravel/database/*.sqlite 2>/dev/null || true
	@rm -rf vendor/orchestra/testbench-core/laravel/storage/framework/cache/* 2>/dev/null || true
	@echo "✅ Testbench fully cleaned"

.PHONY: clean-vendor
clean-vendor: ## Clean vendor directory
	@echo "🧹 Cleaning vendor..."
	@rm -rf vendor/andydefer/laravel-nemesis 2>/dev/null || true
	@echo "✅ Vendor cleaned"

.PHONY: test
test: clean-testbench-all ## Run PHPUnit tests
	@./vendor/bin/phpunit --testdox --display-notices

# ---------------------------------------------------
# Code Quality Tools (Console Output Versions)
# ---------------------------------------------------

.PHONY: lint-php
lint-php: ## Run Pint code formatter (test mode)
	@echo "🛠️  Running Pint code formatter..."
	@$(PINT) --test
	@echo "✅ Pint formatting check completed"

.PHONY: lint-php-fix
lint-php-fix: ## Apply Pint code formatting
	@echo "🛠️  Running Pint code formatter..."
	@$(PINT)
	@echo "✅ Pint formatting applied"

.PHONY: lint-phpstan
lint-phpstan: ## Run PHPStan static analysis
	@echo "🔍 Running PHPStan static analysis..."
	@$(PHPSTAN) analyse src tests --level=max
	@echo "✅ PHPStan analysis completed"

.PHONY: lint-rector
lint-rector: ## Run Rector refactoring
	@echo "🔄 Running Rector refactoring..."
	@$(RECTOR) process
	@echo "✅ Rector refactoring completed"

.PHONY: lint-psalm
lint-psalm: ## Run Psalm static analysis
	@echo "📖 Running Psalm static analysis..."
	@$(PSALM) --show-info=true
	@echo "✅ Psalm analysis completed"

# ---------------------------------------------------
# Code Quality Tools (Markdown Report Versions)
# ---------------------------------------------------

.PHONY: lint-php-md
lint-php-md: ## Run Pint and save report to pint.md
	@echo "📊 Running Pint and saving report to pint.md..."
	@echo "# Pint Code Formatter Report" > pint.md
	@echo "*Generated: $$(date)*" >> pint.md
	@echo "" >> pint.md
	@$(PINT) --test --verbose 2>&1 >> pint.md || true
	@echo "✅ Pint report saved to pint.md"

.PHONY: lint-php-fix-md
lint-php-fix-md: ## Test Pint formatting and save report to pint-test.md
	@echo "📊 Running Pint formatting test and saving report to pint-test.md..."
	@echo "# Pint Formatting Test Report" > pint-test.md
	@echo "*Generated: $$(date)*" >> pint-test.md
	@echo "" >> pint-test.md
	@$(PINT) --test 2>&1 >> pint-test.md || true
	@echo "✅ Pint formatting test report saved to pint-test.md"

.PHONY: lint-phpstan-md
lint-phpstan-md: ## Run PHPStan and save report to phpstan.md
	@echo "📊 Running PHPStan and saving report to phpstan.md..."
	@echo "# PHPStan Static Analysis Report" > phpstan.md
	@echo "*Generated: $$(date)*" >> phpstan.md
	@echo "" >> phpstan.md
	@$(PHPSTAN) analyse src tests --level=max --no-progress 2>&1 >> phpstan.md || true
	@echo "✅ PHPStan report saved to phpstan.md"

.PHONY: lint-rector-md
lint-rector-md: ## Run Rector and save report to rector.md
	@echo "📊 Running Rector and saving report to rector.md..."
	@echo "# Rector Refactoring Report" > rector.md
	@echo "*Generated: $$(date)*" >> rector.md
	@echo "" >> rector.md
	@$(RECTOR) process --dry-run 2>&1 >> rector.md || true
	@echo "✅ Rector report saved to rector.md"

.PHONY: lint-psalm-md
lint-psalm-md: ## Run Psalm and save report to psalm.md
	@echo "📊 Running Psalm and saving report to psalm.md..."
	@echo "# Psalm Static Analysis Report" > psalm.md
	@echo "*Generated: $$(date)*" >> psalm.md
	@echo "" >> psalm.md
	@$(PSALM) --show-info=true --no-progress 2>&1 >> psalm.md || true
	@echo "✅ Psalm report saved to psalm.md"

# ---------------------------------------------------
# Batch Quality Checks (Non-blocking)
# ---------------------------------------------------

.PHONY: lint-all-md
lint-all-md: ## Run all linters and save reports (non-blocking)
	@echo "📦 Running all code quality checks and saving reports..."
	@make lint-php-md
	@make lint-phpstan-md
	@make lint-psalm-md
	@echo "✅ All code quality reports generated"

.PHONY: lint-all-fix-md
lint-all-fix-md: ## Run all fixers and save reports (non-blocking)
	@echo "📦 Running all code fixers and saving reports..."
	@make lint-php-fix-md
	@echo "✅ All code fixer reports generated"

# ---------------------------------------------------
# Release Management Workflow
# ---------------------------------------------------

.PHONY: pre-release
pre-release: ## Run all pre-release checks
	@echo "🚀 Running pre-release checks..."
	@make test
	@make lint-all-md
	@echo "✅ Pre-release checks completed"

.PHONY: release
release: pre-release git-tag ## Create new release (includes pre-release)
	@echo "🚀 Release created successfully"

.PHONY: post-release
post-release: update-all ## Clean up after release
	@echo "🧹 Performing post-release cleanup..."
	@echo "✅ Post-release cleanup completed"

# ---------------------------------------------------
# File Comments
# ---------------------------------------------------

.PHONY: add-file-comments
add-file-comments: ## Add path comments to PHP files
	@echo "📁 Ajout des commentaires de chemin dans les fichiers PHP..."
	@read -p "📂 Entrez les dossiers à parcourir (séparés par des espaces, ex: src tests): " dirs; \
	if [ -z "$$dirs" ]; then \
		echo "❌ Aucun dossier spécifié. Opération annulée."; \
		exit 1; \
	fi; \
	for dir in $$dirs; do \
		if [ ! -d "$$dir" ]; then \
			echo "⚠️  Dossier non trouvé: $$dir"; \
			continue; \
		fi; \
		echo "📂 Traitement du dossier: $$dir"; \
		find $$dir -type f -name "*.php" -not -path "*/vendor/*" | while read file; do \
			line2=$$(sed -n '2p' "$$file"); \
			if echo "$$line2" | grep -q "^//.*\.php"; then \
				echo "⏭️  $$file (déjà commenté)"; \
			else \
				echo "🔧 Ajout du commentaire: $$file"; \
				if head -n 1 "$$file" | grep -q "^<?php"; then \
					sed -i "1s|^<?php|<?php\n// $$file|" "$$file"; \
				else \
					sed -i "1i<?php\n// $$file" "$$file"; \
				fi; \
				echo "✅ $$file"; \
			fi; \
		done; \
	done; \
	echo "✨ Terminé !"

# ---------------------------------------------------
# Help & Documentation
# ---------------------------------------------------

.PHONY: help
help: ## Affiche cette aide
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------
# Default Target
# ---------------------------------------------------
.DEFAULT_GOAL := help