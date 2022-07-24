# HLS Player

A video player that can play any video file in the browser using ffmpeg as backend.

# Introduction

I was impressed with how plex, jellyfin, emby and other media players were able to play any video file in the browser,
and transcode the media on the fly, I wanted something simpler that I can integrate into my server management app, and I
managed to make it work with the majority of the media files that I came cross, and I thought that the player might be
useful for somebody else.

# Container Limitation

Sadly, I was not able to get a good alpine build with working hardware acceleration, so right now in the container build
only software encoding works. However, the video player support hardware accel, if there is interest I may build
different container that has hardware acceleration support.

# How does the player works?

When you select a video file, you will be presented with a screen that give you options on how to encode the video file,
after you finish selecting what you want, and click **Stream** it will hand off the file to a controller that would
segment the video file into chunks to support seeking, we use HLS protocol to support wide devices, we tested on
iOS/chrome/firefox, and the defaults works.

## Install

create your `docker-compose.yaml` file

```yaml
version: '2.3'
services:
    hls_player:
        image: ghcr.io/arabcoders/hls_player:latest
        # To change the user/group id associated with the app change the following line.
        user: "${UID:-1000}:${GID:-1000}"
        container_name: hls_player
        restart: unless-stopped
        ports:
            # App port.
            - "8080:8080" 
        volumes:
            # Directory to store app data. Mounted to container /opt/app/var directory.
            - ./data:/opt/app/var:rw
            # Your media files path. Mounted to /srv in container.
            - /mnt/media:/srv:ro
```

After creating your docker-compose file, start the container.

```bash
$ docker-compose up -d
```

Now, go to `http://localhost:8081/` and browse your media.
