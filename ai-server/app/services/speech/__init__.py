"""Speech: voice message -> text."""

from app.services.speech.transcriber import Transcriber, UnusableAudioError

__all__ = ["Transcriber", "UnusableAudioError"]
