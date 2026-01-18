"""
Zencoder-compatible API routes.

These routes mimic the Zencoder API v2 specification.
"""

import logging
from datetime import datetime
from typing import List, Optional

from fastapi import APIRouter, Depends, Header, HTTPException, Query, BackgroundTasks
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.config import get_settings
from app.database import get_session
from app.models.database import Job, Output
from app.models.zencoder import (
    ZencoderJobRequest,
    ZencoderJobResponse,
    ZencoderJobStatus,
    ZencoderJobDetails,
    ZencoderErrorResponse,
)
from app.services.mediaconvert import get_mediaconvert_service, MediaConvertService
from app.services.translator import get_translator, ZencoderToAWSTranslator
from app.services.webhook import get_webhook_service

logger = logging.getLogger(__name__)
router = APIRouter()


def get_api_key(
    zencoder_api_key: Optional[str] = Header(None, alias="Zencoder-Api-Key"),
    authorization: Optional[str] = Header(None),
) -> str:
    """Extract and validate API key from headers."""
    settings = get_settings()

    api_key = zencoder_api_key
    if not api_key and authorization:
        # Support Bearer token format
        if authorization.startswith("Bearer "):
            api_key = authorization[7:]
        else:
            api_key = authorization

    # If no API key configured, allow all requests (development mode)
    if not settings.api_key:
        return api_key or ""

    if api_key != settings.api_key:
        raise HTTPException(
            status_code=401,
            detail={"errors": ["Invalid API key"]},
        )

    return api_key


async def process_job_completion(job_id: int, session: AsyncSession):
    """Background task to check job completion and send webhooks."""
    webhook_service = get_webhook_service()
    mediaconvert_service = get_mediaconvert_service()

    # Get the job with outputs
    result = await session.execute(
        select(Job).options(selectinload(Job.outputs)).where(Job.id == job_id)
    )
    job = result.scalar_one_or_none()

    if not job or not job.aws_job_id:
        return

    try:
        # Get AWS job status
        aws_job = mediaconvert_service.get_job(job.aws_job_id)
        aws_status = aws_job.get("Status", "")
        zencoder_status = MediaConvertService.map_status_to_zencoder(aws_status)

        # Update job state
        job.state = zencoder_status
        job.progress = MediaConvertService.calculate_progress(aws_job)

        if aws_status in ("COMPLETE", "ERROR", "CANCELED"):
            job.finished_at = datetime.utcnow()

            if aws_status == "ERROR":
                error_msg = aws_job.get("ErrorMessage", "Unknown error")
                job.error_message = error_msg
                job.error_class = aws_job.get("ErrorCode", "TranscodingError")

            # Update outputs
            for output in job.outputs:
                output.state = zencoder_status
                if aws_status == "COMPLETE":
                    output.finished_at = datetime.utcnow()

            # Send notifications
            if job.notifications:
                payload = webhook_service.build_job_notification_payload(
                    job=job.to_zencoder_details(),
                    outputs=[o.to_zencoder_details() for o in job.outputs],
                    input_info=job.input_media_info,
                )
                await webhook_service.send_job_notifications(
                    notifications=job.notifications,
                    payload=payload,
                    event="job_finished" if aws_status == "COMPLETE" else "job_failed",
                )

            # Send per-output notifications
            for output in job.outputs:
                if output.notifications:
                    output_payload = webhook_service.build_output_notification_payload(
                        output=output.to_zencoder_details(),
                        job=job.to_zencoder_details(),
                        input_info=job.input_media_info,
                    )
                    await webhook_service.send_job_notifications(
                        notifications=output.notifications,
                        payload=output_payload,
                        event="output_finished" if aws_status == "COMPLETE" else "output_failed",
                    )

        await session.commit()

    except Exception as e:
        logger.error(f"Error processing job completion for {job_id}: {e}")
        await session.rollback()


@router.post("/jobs", response_model=ZencoderJobResponse)
async def create_job(
    request: ZencoderJobRequest,
    background_tasks: BackgroundTasks,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Create a new transcoding job.

    This endpoint is compatible with Zencoder's POST /v2/jobs API.
    """
    settings = get_settings()
    translator = get_translator()
    mediaconvert_service = get_mediaconvert_service()

    # Check for API key in request body as fallback
    if request.api_key and not api_key:
        api_key = request.api_key

    # Create job record
    job = Job(
        input_url=request.input,
        test_mode=request.test or False,
        region=request.region,
        pass_through=request.pass_through,
        notifications=[
            n if isinstance(n, str) else dict(n) for n in request.notifications
        ] if request.notifications else None,
        state="pending",
        submitted_at=datetime.utcnow(),
    )
    session.add(job)
    await session.flush()  # Get job ID

    # Get outputs
    outputs = request.outputs or []
    if request.output:
        outputs.append(request.output)
    if not outputs:
        from app.models.zencoder import ZencoderOutputRequest
        outputs = [ZencoderOutputRequest()]

    # Create output records
    output_responses = []
    for idx, output_req in enumerate(outputs):
        output = Output(
            job_id=job.id,
            label=output_req.label or f"output_{idx}",
            format=output_req.format,
            output_url=output_req.url or output_req.base_url,
            original_settings=output_req.model_dump(exclude_none=True),
            notifications=[
                n if isinstance(n, str) else dict(n) for n in output_req.notifications
            ] if output_req.notifications else None,
            state="pending",
            submitted_at=datetime.utcnow(),
        )
        session.add(output)
        await session.flush()

        output_responses.append({
            "id": output.id,
            "label": output.label,
            "url": output.output_url,
        })

    # Translate to MediaConvert format
    try:
        mediaconvert_settings = translator.translate_job(request)
    except Exception as e:
        logger.error(f"Failed to translate job: {e}")
        job.state = "failed"
        job.error_message = f"Translation error: {str(e)}"
        job.error_class = "TranslationError"
        await session.commit()
        raise HTTPException(
            status_code=400,
            detail={"errors": [f"Invalid job configuration: {str(e)}"]},
        )

    # Submit to MediaConvert (unless test mode without AWS configured)
    if not request.test or settings.mediaconvert_role_arn:
        try:
            aws_job = mediaconvert_service.create_job(mediaconvert_settings)
            job.aws_job_id = aws_job["Id"]
            job.state = "waiting"

            # Update outputs with AWS state
            for output in job.outputs:
                output.state = "waiting"

            # Schedule background task to monitor completion
            background_tasks.add_task(process_job_completion, job.id, session)

        except Exception as e:
            logger.error(f"Failed to create MediaConvert job: {e}")
            job.state = "failed"
            job.error_message = f"AWS error: {str(e)}"
            job.error_class = "AWSError"
    else:
        # Test mode without AWS - simulate success
        job.state = "finished"
        job.finished_at = datetime.utcnow()
        for output in job.outputs:
            output.state = "finished"
            output.finished_at = datetime.utcnow()

    await session.commit()

    return ZencoderJobResponse(
        id=job.id,
        outputs=output_responses,
        test=request.test,
    )


@router.get("/jobs/{job_id}")
async def get_job(
    job_id: int,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Get job details.

    This endpoint is compatible with Zencoder's GET /v2/jobs/{id} API.
    """
    result = await session.execute(
        select(Job).options(selectinload(Job.outputs)).where(Job.id == job_id)
    )
    job = result.scalar_one_or_none()

    if not job:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Job not found"]},
        )

    return {"job": job.to_zencoder_details()}


@router.get("/jobs/{job_id}/progress")
async def get_job_progress(
    job_id: int,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Get job progress.

    This endpoint is compatible with Zencoder's GET /v2/jobs/{id}/progress API.
    """
    result = await session.execute(
        select(Job).options(selectinload(Job.outputs)).where(Job.id == job_id)
    )
    job = result.scalar_one_or_none()

    if not job:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Job not found"]},
        )

    # If job has AWS ID and is still processing, get live status
    if job.aws_job_id and job.state in ("waiting", "processing"):
        try:
            mediaconvert_service = get_mediaconvert_service()
            aws_job = mediaconvert_service.get_job(job.aws_job_id)
            job.state = MediaConvertService.map_status_to_zencoder(
                aws_job.get("Status", "")
            )
            job.progress = MediaConvertService.calculate_progress(aws_job)
            await session.commit()
        except Exception as e:
            logger.warning(f"Failed to get AWS job status: {e}")

    return job.to_zencoder_status()


@router.put("/jobs/{job_id}/cancel")
async def cancel_job(
    job_id: int,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Cancel a job.

    This endpoint is compatible with Zencoder's PUT /v2/jobs/{id}/cancel API.
    """
    result = await session.execute(
        select(Job).options(selectinload(Job.outputs)).where(Job.id == job_id)
    )
    job = result.scalar_one_or_none()

    if not job:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Job not found"]},
        )

    if job.state in ("finished", "failed", "cancelled"):
        raise HTTPException(
            status_code=409,
            detail={"errors": [f"Job is already {job.state}"]},
        )

    # Cancel in AWS if job has been submitted
    if job.aws_job_id:
        try:
            mediaconvert_service = get_mediaconvert_service()
            mediaconvert_service.cancel_job(job.aws_job_id)
        except Exception as e:
            logger.warning(f"Failed to cancel AWS job: {e}")

    # Update local state
    job.state = "cancelled"
    job.finished_at = datetime.utcnow()
    for output in job.outputs:
        output.state = "cancelled"
        output.finished_at = datetime.utcnow()

    await session.commit()

    return {"id": job.id, "state": job.state}


@router.put("/jobs/{job_id}/resubmit")
async def resubmit_job(
    job_id: int,
    background_tasks: BackgroundTasks,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Resubmit a failed or cancelled job.

    This endpoint is compatible with Zencoder's PUT /v2/jobs/{id}/resubmit API.
    """
    result = await session.execute(
        select(Job).options(selectinload(Job.outputs)).where(Job.id == job_id)
    )
    job = result.scalar_one_or_none()

    if not job:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Job not found"]},
        )

    if job.state not in ("failed", "cancelled"):
        raise HTTPException(
            status_code=409,
            detail={"errors": ["Only failed or cancelled jobs can be resubmitted"]},
        )

    # Rebuild the job request from stored data
    translator = get_translator()
    mediaconvert_service = get_mediaconvert_service()

    # Reset job state
    job.state = "pending"
    job.error_message = None
    job.error_class = None
    job.finished_at = None
    job.submitted_at = datetime.utcnow()

    # Reset output states
    for output in job.outputs:
        output.state = "pending"
        output.error_message = None
        output.error_class = None
        output.finished_at = None
        output.submitted_at = datetime.utcnow()

    # Create new request from stored settings
    from app.models.zencoder import ZencoderJobRequest, ZencoderOutputRequest

    output_requests = []
    for output in job.outputs:
        if output.original_settings:
            output_requests.append(ZencoderOutputRequest(**output.original_settings))

    request = ZencoderJobRequest(
        input=job.input_url,
        outputs=output_requests or None,
        region=job.region,
        test=job.test_mode,
        pass_through=job.pass_through,
    )

    try:
        mediaconvert_settings = translator.translate_job(request)
        aws_job = mediaconvert_service.create_job(mediaconvert_settings)
        job.aws_job_id = aws_job["Id"]
        job.state = "waiting"

        for output in job.outputs:
            output.state = "waiting"

        background_tasks.add_task(process_job_completion, job.id, session)

    except Exception as e:
        logger.error(f"Failed to resubmit job: {e}")
        job.state = "failed"
        job.error_message = f"Resubmit error: {str(e)}"
        job.error_class = "ResubmitError"

    await session.commit()

    return {"id": job.id, "state": job.state}


@router.get("/jobs")
async def list_jobs(
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=100),
    state: Optional[str] = Query(None),
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    List all jobs.

    This endpoint is compatible with Zencoder's GET /v2/jobs API.
    """
    query = select(Job).options(selectinload(Job.outputs))

    if state:
        query = query.where(Job.state == state)

    query = query.order_by(Job.created_at.desc())
    query = query.offset((page - 1) * per_page).limit(per_page)

    result = await session.execute(query)
    jobs = result.scalars().all()

    return [{"job": job.to_zencoder_details()} for job in jobs]


@router.get("/outputs/{output_id}")
async def get_output(
    output_id: int,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Get output details.

    This endpoint is compatible with Zencoder's GET /v2/outputs/{id} API.
    """
    result = await session.execute(
        select(Output).options(selectinload(Output.job)).where(Output.id == output_id)
    )
    output = result.scalar_one_or_none()

    if not output:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Output not found"]},
        )

    return output.to_zencoder_details()


@router.get("/outputs/{output_id}/progress")
async def get_output_progress(
    output_id: int,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Get output progress.

    This endpoint is compatible with Zencoder's GET /v2/outputs/{id}/progress API.
    """
    result = await session.execute(
        select(Output).options(selectinload(Output.job)).where(Output.id == output_id)
    )
    output = result.scalar_one_or_none()

    if not output:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Output not found"]},
        )

    return {
        "state": output.state,
        "progress": output.progress,
        "current_event": output.state,
        "current_event_progress": output.progress,
    }


@router.get("/account")
async def get_account(
    api_key: str = Depends(get_api_key),
):
    """
    Get account information.

    This endpoint is compatible with Zencoder's GET /v2/account API.
    Returns a mock response for compatibility.
    """
    return {
        "account_state": "active",
        "plan": "enterprise",
        "minutes_used": 0,
        "minutes_included": -1,  # Unlimited
        "billing_state": "active",
        "integration_mode": False,
    }


@router.get("/reports/minutes")
async def get_minutes_report(
    api_key: str = Depends(get_api_key),
):
    """
    Get usage minutes report.

    This endpoint is compatible with Zencoder's GET /v2/reports/minutes API.
    Returns a mock response since AWS billing is separate.
    """
    return {
        "total": {
            "live": 0,
            "vod": 0,
            "total": 0,
        },
        "statistics": {
            "by_month": [],
        },
    }


@router.post("/jobs/{job_id}/finish")
async def finish_job(
    job_id: int,
    background_tasks: BackgroundTasks,
    session: AsyncSession = Depends(get_session),
    api_key: str = Depends(get_api_key),
):
    """
    Manually trigger job completion check.

    This is an additional endpoint for forcing a status update.
    """
    result = await session.execute(
        select(Job).options(selectinload(Job.outputs)).where(Job.id == job_id)
    )
    job = result.scalar_one_or_none()

    if not job:
        raise HTTPException(
            status_code=404,
            detail={"errors": ["Job not found"]},
        )

    background_tasks.add_task(process_job_completion, job.id, session)

    return {"id": job.id, "message": "Job completion check scheduled"}
