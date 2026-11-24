# Official Roundcube images plus the directories the tests share with the
# runner. Both supported bases are pinned here; RC_STAGE selects exactly one.
ARG RC_STAGE=rc176

FROM roundcube/roundcubemail:1.7.6-apache@sha256:47f63977a1a1bfe11909cdbe3fa20a0fbdb119dd63eb0b72590c02509f6b7399 AS rc176
FROM roundcube/roundcubemail:1.7.5-apache@sha256:c02e3741d79751648ee687413fb5bd52224cfc338bb708dbcfc80f62c9872481 AS rc1621

FROM ${RC_STAGE}
RUN mkdir -p /var/roundcube/logs /var/roundcube/temp /var/roundcube/testctl \
 && chown www-data:www-data /var/roundcube/logs /var/roundcube/temp /var/roundcube/testctl \
 && chmod 0750 /var/roundcube/temp
