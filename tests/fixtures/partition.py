"""Deterministic file-level suite partitioning for CI sharding.

`--split-group=M/N` runs only the Mth of N groups. Whole test files stay
together (module-scoped fixtures — shared payserver instances — must not be
split across shards), and the assignment is a pure function of the collected
set, so every shard computes the same partition without coordination or a
stored timing file. Test-function count is the weight proxy: crude, but stable
and good enough to keep shards within the same ballpark.
"""
from __future__ import annotations


def parse_split_group(spec: str) -> tuple[int, int]:
    """Parse "M/N" → (M, N), validating 1 <= M <= N."""
    parts = spec.split("/")
    if len(parts) != 2:
        raise ValueError(f"--split-group expects M/N, got {spec!r}")
    try:
        group, total = int(parts[0]), int(parts[1])
    except ValueError:
        raise ValueError(f"--split-group expects integers M/N, got {spec!r}") from None
    if total < 1 or not 1 <= group <= total:
        raise ValueError(f"--split-group needs 1 <= M <= N, got {spec!r}")
    return group, total


def partition_files(weights: dict[str, int], groups: int) -> list[list[str]]:
    """Split files into `groups` buckets, greedily assigning the heaviest file
    to the lightest bucket. Deterministic: ties break on file name for the
    ordering and on bucket index for the assignment."""
    if groups < 1:
        raise ValueError("groups must be >= 1")
    buckets: list[list[str]] = [[] for _ in range(groups)]
    loads = [0] * groups
    for path in sorted(weights, key=lambda p: (-weights[p], p)):
        target = min(range(groups), key=lambda i: (loads[i], i))
        buckets[target].append(path)
        loads[target] += weights[path]
    return buckets
