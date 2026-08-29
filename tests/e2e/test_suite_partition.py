"""Pure-logic tests for the CI suite partitioner (fixtures/partition.py).

No server, no stack — these run in milliseconds on every shard, which also
means every shard self-checks the machinery that decided what it runs.
"""
from __future__ import annotations

import pytest

from fixtures.partition import parse_split_group, partition_files


def test_every_file_lands_in_exactly_one_group() -> None:
    weights = {f"tests/e2e/test_{i}.py": (i % 7) + 1 for i in range(40)}
    buckets = partition_files(weights, 3)
    seen = [f for bucket in buckets for f in bucket]
    assert sorted(seen) == sorted(weights)
    assert len(seen) == len(set(seen))


def test_partition_is_deterministic() -> None:
    weights = {f"f{i}.py": (i * 13) % 11 + 1 for i in range(25)}
    a = partition_files(dict(weights), 4)
    b = partition_files(dict(reversed(list(weights.items()))), 4)
    assert a == b


def test_groups_are_roughly_balanced() -> None:
    weights = {f"f{i}.py": w for i, w in enumerate([14, 9, 9, 7, 5, 3, 3, 2, 1, 1])}
    buckets = partition_files(weights, 3)
    loads = sorted(sum(weights[f] for f in b) for b in buckets)
    # Greedy bin packing: the heaviest bucket exceeds the lightest by at most
    # the largest single-file weight.
    assert loads[-1] - loads[0] <= max(weights.values())


def test_single_group_gets_everything() -> None:
    weights = {"a.py": 3, "b.py": 1}
    assert sorted(partition_files(weights, 1)[0]) == ["a.py", "b.py"]


def test_more_groups_than_files_leaves_empty_buckets() -> None:
    weights = {"a.py": 3, "b.py": 1}
    buckets = partition_files(weights, 4)
    assert sum(len(b) for b in buckets) == 2


def test_parse_split_group_accepts_valid_specs() -> None:
    assert parse_split_group("1/1") == (1, 1)
    assert parse_split_group("2/3") == (2, 3)


@pytest.mark.parametrize("spec", ["", "3", "0/3", "4/3", "a/b", "1/0", "1/2/3", "-1/2"])
def test_parse_split_group_rejects_invalid_specs(spec: str) -> None:
    with pytest.raises(ValueError):
        parse_split_group(spec)
