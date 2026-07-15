FROM node:20-alpine

WORKDIR /app

# Install deps first for better layer caching
COPY package.json ./
RUN npm install --omit=dev

# App source
COPY server.js ./
COPY public ./public

# Persisted channel selection lives here (mount a volume at /data)
RUN mkdir -p /data
ENV PORT=8080 \
    DATA_DIR=/data

EXPOSE 8080

CMD ["node", "server.js"]
