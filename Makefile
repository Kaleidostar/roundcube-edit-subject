.PHONY: test test-16 test-cache test-all release

# Roundcube 1.7.6
test:
	./run-tests.sh

# Roundcube 1.6.21
test-16:
	RC_TAG=1.6.21-apache ./run-tests.sh

# Roundcube 1.7.6 with messages_cache
test-cache:
	MESSAGES_CACHE=1 ./run-tests.sh

# all three acceptance runs, each one runs even if a previous one failed
test-all:
	@rc=0; \
	./run-tests.sh || rc=1; \
	RC_TAG=1.6.21-apache ./run-tests.sh || rc=1; \
	MESSAGES_CACHE=1 ./run-tests.sh || rc=1; \
	exit $$rc

release:
	./release.sh
