FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MODEL_PATH=/app/artifacts/scam_model.joblib

WORKDIR /app

RUN useradd --create-home --uid 10001 loopbuy

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY data ./data
COPY train.py ./
RUN mkdir -p /app/artifacts && python train.py && chown -R loopbuy:loopbuy /app

USER loopbuy
EXPOSE 8000

FROM base AS test
USER root
COPY requirements-dev.txt ./
RUN pip install --no-cache-dir -r requirements-dev.txt
COPY --chown=loopbuy:loopbuy tests ./tests
USER loopbuy
CMD ["python", "-m", "pytest", "-q", "tests"]

FROM base AS runtime
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
