FROM golang:1.25-alpine AS build

WORKDIR /src
COPY go.mod go.sum* ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/loopbuy-api ./cmd/api

FROM alpine:3.22
RUN apk add --no-cache ca-certificates tzdata \
    && addgroup -S -g 10001 loopbuy \
    && adduser -S -D -H -u 10001 -G loopbuy loopbuy \
    && mkdir -p /var/lib/loopbuy/media \
    && chown -R loopbuy:loopbuy /var/lib/loopbuy
COPY --from=build /out/loopbuy-api /usr/local/bin/loopbuy-api
USER loopbuy
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/loopbuy-api"]
