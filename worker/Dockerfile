FROM public.ecr.aws/lambda/python:3.12
COPY requirements.lock /tmp/requirements.lock
RUN pip install --no-cache-dir -r /tmp/requirements.lock --target "${LAMBDA_TASK_ROOT}"
COPY worker ${LAMBDA_TASK_ROOT}/worker
CMD ["worker.handler.handler"]
