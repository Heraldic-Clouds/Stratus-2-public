import pathlib
import xml.etree.ElementTree as ET

import yaml
from unittest.mock import Mock

from scripts import aws_support


ROOT = pathlib.Path(__file__).resolve().parents[1]


def test_node_clients_use_the_timer_region(monkeypatch):
    monkeypatch.setenv("AWS_REGION", "us-west-2")
    monkeypatch.delenv("AWS_DEFAULT_REGION", raising=False)
    factory = Mock()
    monkeypatch.setattr(aws_support.boto3, "client", factory)
    aws_support.client("cloudwatch")
    assert factory.call_args.kwargs["region_name"] == "us-west-2"


def test_nextcloud_registration_metadata_is_valid_xml():
    document = ET.parse(ROOT / "apps/stratus2_ai/appinfo/info.xml")
    assert document.findtext("id") == "stratus2_ai"
    assert document.findtext("namespace") == "Stratus2AI"
    assert document.find("navigations/navigation/route") is not None


def test_database_network_policy_does_not_allow_external_connections():
    documents = list(yaml.safe_load_all((ROOT / "deploy/kubernetes.yaml").read_text()))
    policy = next(item for item in documents if item["kind"] == "NetworkPolicy")
    assert policy["spec"]["egress"] == []
    assert policy["spec"]["ingress"][0]["from"] == [{"podSelector": {"matchLabels": {"app": "nextcloud"}}}]


def test_public_workflow_has_no_deployment_permissions():
    workflows = list((ROOT / ".github/workflows").glob("*.yml"))
    assert [path.name for path in workflows] == ["ci.yml"]
    workflow = yaml.safe_load(workflows[0].read_text())
    assert workflow["permissions"] == {"contents": "read"}
    assert all("permissions" not in job and "environment" not in job for job in workflow["jobs"].values())
