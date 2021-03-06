start:
	docker kill crackcave-audio-management || true
	docker rm crackcave-audio-management || true
	docker build -t crackcave-audio-management-image .
	docker run -d -p 8000:8000 --name="crackcave-audio-management" crackcave-audio-management-image

logs:
	docker logs crackcave-audio-management

exec:
	docker exec -ti crackcave-audio-management /bin/sh
