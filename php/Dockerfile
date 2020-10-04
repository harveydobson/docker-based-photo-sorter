FROM php:7.4-cli
RUN docker-php-ext-install pdo pdo_mysql && \
docker-php-ext-install exif && \
docker-php-ext-enable exif

###########################################################################
# FFMPEG:
###########################################################################

USER root

ARG INSTALL_FFMPEG=false

RUN if [ ${INSTALL_FFMPEG} = true ]; then \
    apt-get update -yqq && \
    apt-get -y install ffmpeg \
;fi

WORKDIR /home/code
RUN mkdir /home/data && mkdir /home/log
COPY ./code /home/code

CMD [ "sh", "-c", "./run.sh" ]